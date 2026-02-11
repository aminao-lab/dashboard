// ==========================
// CONFIGURATION GLOBALE
// ==========================
// On n'utilise plus l'ancienne API worker (BASE_URL/TOKEN)
// On consomme nos endpoints PHP locaux (/api/...)
async function fetchJSON(url) {
  const res = await fetch(url);
  const json = await res.json();
  return json;
}

// Utilitaire pour formater une date ISO -> dd/mm/yyyy
function toFRDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString("fr-FR", { day: "2-digit", month: "2-digit", year: "numeric" });
}

// Construit des “rows” à partir de me.php
function buildRowsFromMe(me) {
  const userRow = { user_id: me.user_id, username: me.username };

  const tempsRow = {
    user_id: me.user_id,
    date_maj: me.date_maj ?? null,
    "6eme": me.temps_6eme ?? 0,
    "5eme": me.temps_5eme ?? 0,
    "4eme": me.temps_4eme ?? 0,
    "3eme": me.temps_3eme ?? 0,
    "2nde": me.temps_2nde ?? 0,
    "1ere": me.temps_1ere ?? 0,
    term: me.temps_term ?? 0,
    "term-pc": me.temps_term_pc ?? 0,
  };

  const progRow = {
    user_id: me.user_id,
    date_maj: me.date_maj ?? null,
    "6eme": me.prog_6eme ?? 0,
    "5eme": me.prog_5eme ?? 0,
    "4eme": me.prog_4eme ?? 0,
    "3eme": me.prog_3eme ?? 0,
    "2nde": me.prog_2nde ?? 0,
    "1ere": me.prog_1ere ?? 0,
    term: me.prog_term ?? 0,
    "term-pc": me.prog_term_pc ?? 0,
  };

  return { userRow, tempsRow, progRow };
}

// Correspond aux colonnes de Temps_Niveau et Temps_Week
const LEVEL_COLUMNS = [
  "-",
  "6eme",
  "5eme",
  "4eme",
  "3eme",
  "2nde",
  "1ere",
  "term",
  "term-pc",
];

let CURRENT_USER_ID = null; // Nouvelle ligne : user_id sera déterminé via /api/me.php (session)

// ==========================
// VARIABLES
// ==========================
let usersData = [];
let tempsNiveauData = [];
let tempsWeekData = [];
let progressionData = [];

let selectedLevel = "-";

// Index pour navigation semaines
let currentWeekIndex = 0;
let currentBarsStartIndex = 0;

// Tableau global des semaines complètes pour l'histogramme
let allCompleteWeeks = [];

// ==========================
// FETCH DATA VIA WORKER
// ==========================
async function fetchEndpoint(endpoint) {
  const url = `${CONFIG.BASE_URL}/?endpoint=${endpoint}&token=${CONFIG.TOKEN}`;
  const res = await fetch(url);
  const json = await res.json();

  if (!json.success) {
    throw new Error(`Erreur API: ${json.error}`);
  }

  return json.data;
}

// ==================================================
// LOAD DATA (via /api/me.php + /api/temps_week.php)
// ==================================================
async function loadData() {
  try {
    // Afficher le loader
    loaderEl.style.display = "flex";
    dashboardEl.style.display = "none";

    // Nouvelle ligne : récupérer l'utilisateur via la session serveur
    const meRes = await fetch("/api/me.php");
    const meJson = await meRes.json();

    if (!meJson.ok) {
      // Nouvelle ligne : message clair si session absente/expirée
      throw new Error(meJson.error || "Session expirée. Recharge le lien signé.");
    }

    const me = meJson.me;
    CURRENT_USER_ID = me.user_id; // Nouvelle ligne : ID déterminé depuis la session

    // Nouvelle ligne : construire les structures attendues par le dashboard
    const userRow = { user_id: me.user_id, username: me.username };
    const tempsRow = {
      user_id: me.user_id,
      date_maj: me.date_maj ?? null,
      "6eme": me.temps_6eme ?? 0,
      "5eme": me.temps_5eme ?? 0,
      "4eme": me.temps_4eme ?? 0,
      "3eme": me.temps_3eme ?? 0,
      "2nde": me.temps_2nde ?? 0,
      "1ere": me.temps_1ere ?? 0,
      term: me.temps_term ?? 0,
      "term-pc": me.temps_term_pc ?? 0,
    };
    const progRow = {
      user_id: me.user_id,
      date_maj: me.date_maj ?? null,
      "6eme": me.prog_6eme ?? 0,
      "5eme": me.prog_5eme ?? 0,
      "4eme": me.prog_4eme ?? 0,
      "3eme": me.prog_3eme ?? 0,
      "2nde": me.prog_2nde ?? 0,
      "1ere": me.prog_1ere ?? 0,
      term: me.prog_term ?? 0,
      "term-pc": me.prog_term_pc ?? 0,
    };

    usersData = [userRow];
    tempsNiveauData = [tempsRow];
    progressionData = [progRow];

    // Nouvelle ligne : récupérer temps_week via session
    const weekRes = await fetch("/api/temps_week.php");
    const weekJson = await weekRes.json();
    if (!weekJson.ok) {
      throw new Error(weekJson.error || "Erreur /api/temps_week.php");
    }

    // (si tu as toFRDate dans ton fichier, garde-le ; sinon tu peux enlever cette map)
    tempsWeekData = (weekJson.rows || []).map((r) => ({
      ...r,
      // si r.debute_le est déjà "dd/mm/yyyy", ça ne casse pas
      debute_le: r.debute_le,
      finit_le: r.finit_le,
    }));

    // Init + UI
    initUser();
    initSelect();
    initIndexes();
    setupBarsNavigation();
    updateUI();

    await loadRegularity();

    loaderEl.style.display = "none";
    dashboardEl.style.display = "block";
  } catch (error) {
    console.error("❌ Erreur chargement données:", error);
    loaderEl.style.display = "none";

    // Nouvelle ligne : message mis à jour (plus de mention user_id dans l'URL)
    alert("Impossible de charger les données. Vérifie que ta session est active (lien signé) et que les endpoints /api/me.php et /api/temps_week.php répondent.");
  }
}

// ==========================
// Régularité 
// ==========================
async function loadRegularity() {
  try {
    console.log("loadRegularity() called");

    const res = await fetch("/api/regularity.php", { credentials: "include" });
    const json = await res.json();

    console.log("regularity json:", json);

    if (!json.ok) return;

    const activeDays = Number(json.active_days ?? 0);
    const tier = json.tier; // "bronze" | "silver" | "gold" | "platinum" | "diamond" | null
    const nextTier = json.next_tier; // idem ou null
    const daysToNext = Number(json.days_to_next ?? 0);

    // =========================
    // 1) Résumé sous le titre
    // =========================

    // Période lisible "ce mois-ci"
    const periodEl = document.getElementById("regularity-period");
    if (periodEl && json.month) {
      const [y, m] = String(json.month).split("-");
      const monthName = new Date(Number(y), Number(m) - 1, 1).toLocaleDateString(
        "fr-FR",
        { month: "long", year: "numeric" },
      );
      periodEl.textContent = `Ce mois-ci (${monthName})`;
    }

    // KPI "X connexions ce mois ci"
    const daysEl = document.getElementById("regularity-days");
    if (daysEl) {
      daysEl.textContent = `${activeDays} connexion${activeDays > 1 ? "s" : ""} ce mois-ci`;
    }

    // =========================
    // 2) Barre de progression
    // =========================
    const maxDays = 25;
    const percent = Math.min(activeDays / maxDays, 1) * 100;

    const fill = document.getElementById("regularity-fill");
    if (fill) fill.style.width = percent + "%";

    // =========================
    // 3) Stations (états visuels)
    // =========================
    const tiersOrder = ["bronze", "silver", "gold", "platinum", "diamond"];
    
    const TIER_LIMITS = { bronze: 3, silver: 6, gold: 12, platinum: 19, diamond: 25 };
    const TIER_LABELS = { bronze: "Bronze", silver: "Argent", gold: "Or", platinum: "Platine", diamond: "Diamant" };
    
    // Important : on cible UNIQUEMENT les stations du bloc régularité
    const stationsRoot = document.getElementById("regularity-stations");
    const stations = stationsRoot
      ? stationsRoot.querySelectorAll(".station")
      : document.querySelectorAll("#regularity .station");

    stations.forEach((el) => {
      const t = el.dataset.tier;
      el.classList.remove("reached", "current", "locked");

      if (!tier) {
        el.classList.add("locked");
        return;
      }

      const idxT = tiersOrder.indexOf(t);
      const idxTier = tiersOrder.indexOf(tier);

      if (idxT === -1 || idxTier === -1) {
        el.classList.add("locked");
      } else if (idxT < idxTier) {
        el.classList.add("reached");
      } else if (idxT === idxTier) {
        el.classList.add("current");
      } else {
        el.classList.add("locked");
      }
    });

    stations.forEach((el) => {
      const t = el.dataset.tier;
      const tooltip = el.querySelector(".regularity-tooltip");
      if (!t || !tooltip) return;

      const label = TIER_LABELS[t] || t;
      const limit = TIER_LIMITS[t] || 0;

      let text = "";

      if (el.classList.contains("reached")) {
        text = `Badge ${label}. Déjà débloqué ce mois-ci ✔️`;
      } else if (el.classList.contains("current")) {
        text = `Badge ${label}. Ton niveau actuel 🌟`;
      } else {
        const remaining = Math.max(0, limit - activeDays);
        text = `Badge ${label}. Plus que ${remaining} connexion${remaining > 1 ? "s" : ""} pour l’atteindre 💪`;
      }

      tooltip.textContent = text;
    });

    // =========================
    // 4) Message motivant
    // =========================
    const msgEl = document.getElementById("regularity-message");
    if (msgEl) {
      msgEl.style.display = "block";

      if (!tier) {
        msgEl.textContent =
          "Plus tu te connectes régulièrement, plus tu montes en niveau et débloques de nouveaux badges !";
      } else if (!nextTier) {
        msgEl.textContent =
          "💎 Niveau Diamant atteint ! Ta régularité est exceptionnelle, bravo !";
      } else {
        msgEl.textContent =
          `Encore ${daysToNext} jour${daysToNext > 1 ? "s" : ""} pour atteindre le niveau ${String(nextTier).toUpperCase()} 💪`;
      }
    }

    // =========================
    // 5) Tooltip au survol des stations
    // =========================
    const thresholds = {
      bronze: 3,
      silver: 6,
      gold: 12,
      platinum: 19,
      diamond: 25,
    };

    const tooltip = document.getElementById("regularity-tooltip");
    const stationsRoot2 = document.getElementById("regularity-stations");
    const stationEls = stationsRoot2
      ? stationsRoot2.querySelectorAll(".station")
      : [];

    function tierLabel(t) {
      const map = {
        bronze: "Bronze",
        silver: "Argent",
        gold: "Or",
        platinum: "Platine",
        diamond: "Diamant",
      };
      return map[t] || t;
    }

    if (tooltip && stationEls.length) {
      stationEls.forEach((el) => {
        const t = el.dataset.tier;
        const needed = thresholds[t];

        el.addEventListener("mousemove", (ev) => {
          if (!needed) return;

          const remaining = Math.max(0, needed - activeDays);

          let line1 = `${tierLabel(t)} — ${needed} jour${needed > 1 ? "s" : ""}`;
          let line2 = "";

          if (activeDays >= needed) {
            line2 = "✅ Palier atteint";
          } else {
            line2 = `Encore ${remaining} jour${remaining > 1 ? "s" : ""} pour l’atteindre 💪`;
          }

          tooltip.innerHTML = `<strong>${line1}</strong><br>${line2}`;
          tooltip.style.display = "block";
          tooltip.style.left = ev.clientX + "px";
          tooltip.style.top = ev.clientY + "px";
        });

        el.addEventListener("mouseleave", () => {
          tooltip.style.display = "none";
        });
      });
    }

  } catch (e) {
    console.warn("Regularity not loaded", e);
  }
}

// ==========================
// UTILS TEMPS → format humain
// ==========================
function formatSeconds(seconds) {
  seconds = Number(seconds);

  if (isNaN(seconds) || seconds === 0) return "0 HEURE";
  if (seconds < 60) return `${seconds} SECONDES`;

  let totalMinutes = Math.floor(seconds / 60);

  if (totalMinutes < 60) {
    return `${totalMinutes} MINUTE${totalMinutes > 1 ? "S" : ""}`;
  }

  let hours = Math.floor(totalMinutes / 60);
  let remainingMinutes = totalMinutes % 60;

  if (remainingMinutes === 0) {
    return hours === 1 ? `1 HEURE` : `${hours} HEURES`;
  }

  return `${hours}H${remainingMinutes.toString().padStart(2, "0")}`;
}

// ==========================
// UTILS DATE → format humain
// ==========================
function getWeekDateRange(isoWeekStr) {
  try {
    const [year, week] = isoWeekStr.split("-W").map(Number);

    const jan4 = new Date(year, 0, 4);
    const jan4Day = jan4.getDay();

    const mondayWeek1 = new Date(jan4);
    mondayWeek1.setDate(jan4.getDate() - ((jan4Day + 6) % 7));

    const startDate = new Date(mondayWeek1);
    startDate.setDate(startDate.getDate() + (week - 1) * 7);

    const endDate = new Date(startDate);
    endDate.setDate(endDate.getDate() + 6);

    const fmt = (d) =>
      d.toLocaleDateString("fr-FR", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
      });

    return {
      start: fmt(startDate),
      end: fmt(endDate),
    };
  } catch (error) {
    console.error("Erreur getWeekDateRange:", isoWeekStr, error);
    return { start: "—", end: "—" };
  }
}

// ==========================
// DOM REFERENCES
// ==========================
const usernameEl = document.getElementById("username");
const lastUpdatedEl = document.getElementById("last-updated");
const selectLevel = document.getElementById("niveau");
const globalHoursEl = document.getElementById("total-hours");
const globalScoreEl = document.getElementById("score");
const globalScoreCommentEl = document.getElementById("score-comment");
const weekHoursEl = document.getElementById("week-hours-display");
const weekRangeEl = document.getElementById("week-range");
const weekPrevBtn = document.getElementById("week-prev");
const weekNextBtn = document.getElementById("week-next");
const barsContainer = document.getElementById("bars");
const chartStartEl = document.getElementById("chart-start");
const chartEndEl = document.getElementById("chart-end");
const loaderEl = document.getElementById("loader");
const dashboardEl = document.getElementById("dashboard");
const selectLevelMessageEl = document.getElementById("select-level-message");
const dashboardContentEl = document.getElementById("dashboard-content");

// ==========================
// INIT USER
// ==========================
function initUser() {
  const niveauRow = tempsNiveauData.find((r) => r.user_id === CURRENT_USER_ID);
  const user = usersData.find((u) => u.user_id === CURRENT_USER_ID);

  usernameEl.textContent = user?.username ?? "Utilisateur";
  lastUpdatedEl.textContent = niveauRow?.date_maj ?? "-";

}

// ==========================
// SELECT LEVEL
// ==========================
function initSelect() {
  // Vider le select pour éviter les doublons
  selectLevel.innerHTML = "";

  LEVEL_COLUMNS.forEach((level) => {
    const option = document.createElement("option");
    option.value = level;
    option.textContent =
      level === "-" ? "Sélectionnez un niveau" : level.toUpperCase();
    selectLevel.appendChild(option);
  });

  // Event listener pour le changement de niveau
  selectLevel.addEventListener("change", handleLevelChange);

  console.log("✅ Select initialisé avec", LEVEL_COLUMNS.length, "options");
}

// ==========================
// HANDLER CHANGEMENT DE NIVEAU
// ==========================
function handleLevelChange(event) {
  const newLevel = event.target.value;
  console.log("🔄 Changement de niveau:", selectedLevel, "→", newLevel);

  selectedLevel = newLevel;

  try {
    // Réinitialiser les index
    currentWeekIndex = 0;
    currentBarsStartIndex = 0;

    // Reconstruire et mettre à jour
    buildAllCompleteWeeks();
    updateUI();

    console.log("✅ Mise à jour réussie pour le niveau:", selectedLevel);
  } catch (error) {
    console.error("❌ Erreur lors du changement de niveau:", error);
    alert("Une erreur est survenue. Veuillez rafraîchir la page.");
  }
}

// ==========================
// INIT INDEXES POUR NAVIGATION
// ==========================
function initIndexes() {
  const rows = tempsWeekData.filter((r) => r.user_id === CURRENT_USER_ID);

  if (rows.length === 0) {
    currentWeekIndex = 0;
    currentBarsStartIndex = 0;
    return;
  }

  currentWeekIndex = rows.length - 1;
  buildAllCompleteWeeks();
  currentBarsStartIndex = Math.max(0, allCompleteWeeks.length - 4);
}

// ==========================
// CONSTRUIRE TOUTES LES SEMAINES COMPLÈTES
// ==========================
function buildAllCompleteWeeks() {
  allCompleteWeeks = [];

  if (selectedLevel === "-") {
    console.log("⚠️ Aucun niveau sélectionné");
    return;
  }

  try {
    let rows = tempsWeekData
      .filter((r) => r.user_id === CURRENT_USER_ID)
      .filter((r) => r.semaine && r.semaine.includes("-W"))
      .map((r) => ({ ...r }))
      .sort((a, b) => {
        const dateA = a.debute_le
          ? new Date(a.debute_le.split("/").reverse().join("-"))
          : new Date(0);
        const dateB = b.debute_le
          ? new Date(b.debute_le.split("/").reverse().join("-"))
          : new Date(0);
        return dateA - dateB;
      });

    if (rows.length === 0) {
      console.log("⚠️ Aucune semaine disponible pour cet utilisateur");
      return;
    }

    const first = rows[0];

    // ✅ Calculer la semaine actuelle - 1 (dernière semaine complète)
    const today = new Date();
    const currentWeekInfo = getCurrentISOWeek(today);

    // Soustraire 1 semaine
    const lastCompleteWeekInfo = getPreviousWeek(
      currentWeekInfo.year,
      currentWeekInfo.week,
    );
    const endYear = lastCompleteWeekInfo.year;
    const endWeek = lastCompleteWeekInfo.week;

    console.log(`📅 PREMIÈRE SEMAINE (Google Sheets): ${first.semaine}`);
    console.log(
      `📅 SEMAINE ACTUELLE: ${currentWeekInfo.year}-W${currentWeekInfo.week
        .toString()
        .padStart(2, "0")}`,
    );
    console.log(
      `📅 DERNIÈRE SEMAINE COMPLÈTE (affichée): ${endYear}-W${endWeek
        .toString()
        .padStart(2, "0")}`,
    );

    if (!first.semaine) {
      console.error("❌ Format de semaine invalide");
      return;
    }

    let startYear = parseInt(first.semaine.split("-W")[0]);
    let startWeek = parseInt(first.semaine.split("-W")[1]);

    console.log(`\n🔢 START: Année ${startYear}, Semaine ${startWeek}`);
    console.log(
      `🔢 END: Année ${endYear}, Semaine ${endWeek} (dernière semaine complète)`,
    );

    function nextWeek(year, week) {
      week++;
      if (week > 52) {
        year++;
        week = 1;
      }
      return { year, week };
    }

    let y = startYear;
    let w = startWeek;
    let iterations = 0;
    const MAX_ITERATIONS = 520;

    console.log("\n🔄 DÉBUT DE LA BOUCLE:");

    while (iterations < MAX_ITERATIONS) {
      iterations++;

      const weekStr = `${y}-W${w.toString().padStart(2, "0")}`;
      let row = rows.find((r) => r.semaine === weekStr);

      if (!row) {
        // ✅ Semaine manquante : créer avec temps = 0
        row = {
          user_id: CURRENT_USER_ID,
          semaine: weekStr,
        };
        row[selectedLevel] = 0;

        const range = getWeekDateRange(weekStr);
        row.debute_le = range.start;
        row.finit_le = range.end;

        console.log(
          `⚠️ Semaine MANQUANTE: ${weekStr} | ${row.debute_le} → ${row.finit_le} | Temps: 0`,
        );
      } else {
        console.log(
          `✅ Semaine EXISTANTE: ${weekStr} | ${row.debute_le} → ${row.finit_le} | Temps: ${row[selectedLevel]}`,
        );
      }

      allCompleteWeeks.push(row);

      // ✅ Arrêter APRÈS avoir traité la dernière semaine complète
      if (y === endYear && w === endWeek) {
        console.log(`🛑 Dernière semaine complète atteinte : ${weekStr}`);
        break;
      }

      ({ year: y, week: w } = nextWeek(y, w));
    }

    if (iterations >= MAX_ITERATIONS) {
      console.error("⚠️ Boucle infinie détectée, arrêt forcé");
    }

    console.log("\n✅ Semaines construites:", allCompleteWeeks.length);
    console.log("\n📊 DERNIÈRES SEMAINES CONSTRUITES:");
    allCompleteWeeks.slice(-5).forEach((r) => {
      console.log(
        `  ${r.semaine} | ${r.debute_le} → ${r.finit_le} | Temps: ${r[selectedLevel]}`,
      );
    });

    // Réinitialiser l'index de la semaine courante
    currentWeekIndex = Math.max(0, allCompleteWeeks.length - 1);
    currentBarsStartIndex = Math.max(0, allCompleteWeeks.length - 4);
  } catch (error) {
    console.error("❌ Erreur buildAllCompleteWeeks:", error);
    console.error("Stack:", error.stack);
    allCompleteWeeks = [];
  }
}

// ==========================
// FONCTION UTILITAIRE : Obtenir la semaine ISO actuelle
// ==========================
function getCurrentISOWeek(date) {
  const d = new Date(date);

  // ISO 8601 : La semaine commence le lundi
  const dayOfWeek = (d.getDay() + 6) % 7;

  // Trouver le jeudi de la semaine actuelle
  d.setDate(d.getDate() - dayOfWeek + 3);

  // Janvier 4 est toujours dans la semaine 1
  const jan4 = new Date(d.getFullYear(), 0, 4);

  // Calculer le nombre de semaines
  const weekNumber = Math.ceil(((d - jan4) / 86400000 + jan4.getDay() + 1) / 7);

  return {
    year: d.getFullYear(),
    week: weekNumber,
  };
}

// ==========================
// FONCTION UTILITAIRE : Obtenir la semaine précédente
// ==========================
function getPreviousWeek(year, week) {
  week--;
  if (week < 1) {
    year--;
    week = 52;
  }
  return { year, week };
}

// ==========================
// SETUP NAVIGATION HISTOGRAMME
// ==========================
function setupBarsNavigation() {
  const prevArrow = document.getElementById("bar-prev");
  const nextArrow = document.getElementById("bar-next");

  prevArrow.addEventListener("click", () => {
    if (currentBarsStartIndex > 0) {
      currentBarsStartIndex--;
      updateBars();
    }
  });

  nextArrow.addEventListener("click", () => {
    const maxIndex = Math.max(0, allCompleteWeeks.length - 4);
    if (currentBarsStartIndex < maxIndex) {
      currentBarsStartIndex++;
      updateBars();
    }
  });
}

// ==========================
// UI UPDATE GLOBAL
// ==========================
function updateUI() {
  console.log("🔄 Mise à jour UI pour le niveau:", selectedLevel);

  // Aucun niveau sélectionné
  if (selectedLevel === "-") {
    selectLevelMessageEl.style.display = "block";
    dashboardContentEl.style.display = "none";
    return;
  }

  // Niveau sélectionné
  selectLevelMessageEl.style.display = "none";
  dashboardContentEl.style.display = "block";

  try {
    updateGlobalData();
    updateWeekCard();
    updateBars();
    updateRecapLists();
    console.log("✅ UI mise à jour");
  } catch (error) {
    console.error("❌ Erreur updateUI:", error);
  }
}

// ==========================
// GLOBAL TEMPS TOTAL + PROGRESSION
// ==========================
function updateGlobalData() {
  if (selectedLevel === "-") {
    globalHoursEl.textContent = "0";
    globalScoreEl.textContent = "0";
    globalScoreCommentEl.textContent = "Sélectionnez un niveau";
    return;
  }

  try {
    // TEMPS TOTAL
    const row = tempsNiveauData.find((r) => r.user_id === CURRENT_USER_ID);

    if (!row) {
      console.warn("⚠️ Utilisateur non trouvé dans tempsNiveauData");
      globalHoursEl.textContent = "0";
    } else {
      const timeValue = row[selectedLevel];
      console.log(`Temps ${selectedLevel}:`, timeValue);
      globalHoursEl.textContent = formatSeconds(timeValue || 0);
    }

    // SCORE DE PROGRESSION
    const progressRow = progressionData.find(
      (r) => r.user_id === CURRENT_USER_ID,
    );

    if (!progressRow) {
      globalScoreEl.textContent = "0";
      globalScoreCommentEl.textContent = "Aucune donnée";
      return;
    }

    const score = Number(progressRow[selectedLevel] || 0);
    globalScoreEl.textContent = Math.round(score);

    if (score === 0) {
      globalScoreCommentEl.textContent = "Vous n'avez pas encore commencé";
    } else if (score < 25) {
      globalScoreCommentEl.textContent = "Bon début, continuez ! 💪";
    } else if (score < 50) {
      globalScoreCommentEl.textContent = "Vous progressez bien ! 🚀";
    } else if (score < 75) {
      globalScoreCommentEl.textContent = "Excellent travail ! 🌟";
    } else if (score < 100) {
      globalScoreCommentEl.textContent = "Presque au sommet ! 🏆";
    } else {
      globalScoreCommentEl.textContent = "Niveau complété ! 🎉";
    }
  } catch (error) {
    console.error("❌ Erreur updateGlobalData:", error);
  }
}

// ==========================
// SEMAINE CARD
// ==========================
function updateWeekCard() {
  if (selectedLevel === "-") {
    weekHoursEl.textContent = "0 Heure";
    weekRangeEl.textContent = "Aucun niveau sélectionné";
    weekPrevBtn.style.visibility = "hidden";
    weekNextBtn.style.visibility = "hidden";
    return;
  }

  if (!allCompleteWeeks.length || allCompleteWeeks.length === 0) {
    weekHoursEl.textContent = "0 Heure";
    weekRangeEl.textContent = "Aucune semaine disponible";
    weekPrevBtn.style.visibility = "hidden";
    weekNextBtn.style.visibility = "hidden";
    return;
  }

  try {
    const totalWeeks = allCompleteWeeks.length;

    if (currentWeekIndex < 0) currentWeekIndex = 0;
    if (currentWeekIndex >= totalWeeks) currentWeekIndex = totalWeeks - 1;

    const row = allCompleteWeeks[currentWeekIndex] || {};

    weekHoursEl.textContent = formatSeconds(row[selectedLevel] || 0);
    weekRangeEl.textContent = `Du ${row.debute_le ?? '-'} au ${row.finit_le ?? '-'}`;

    // Flèches : visibles uniquement si navigation possible (pas lié au temps)
    if (totalWeeks <= 1) {
      weekPrevBtn.style.visibility = "hidden";
      weekNextBtn.style.visibility = "hidden";
    } else {
      weekPrevBtn.style.visibility = currentWeekIndex > 0 ? "visible" : "hidden";
      weekNextBtn.style.visibility = currentWeekIndex < totalWeeks - 1 ? "visible" : "hidden";
    }

    weekNextBtn.onclick = () => {
      if (currentWeekIndex < totalWeeks - 1) {
        currentWeekIndex++;
        updateWeekCard();
      }
    };
  } catch (error) {
    console.error("❌ Erreur updateWeekCard:", error);
  }
}

// ==========================
// HISTOGRAMME AVEC NAVIGATION
// ==========================
function updateBars() {
  barsContainer.innerHTML = "";

  const prevArrow = document.getElementById("bar-prev");
  const nextArrow = document.getElementById("bar-next");

  if (selectedLevel === "-" || allCompleteWeeks.length === 0) {
    chartStartEl.textContent = "—";
    chartEndEl.textContent = "—";
    prevArrow.style.visibility = "hidden";
    nextArrow.style.visibility = "hidden";
    return;
  }

  try {
    const totalWeeks = allCompleteWeeks.length;

    if (currentBarsStartIndex < 0) currentBarsStartIndex = 0;
    const maxIndex = Math.max(0, totalWeeks - 4);
    if (currentBarsStartIndex > maxIndex) currentBarsStartIndex = maxIndex;

    const windowWeeks = allCompleteWeeks.slice(
      currentBarsStartIndex,
      currentBarsStartIndex + 4,
    );

    // Gestion des flèches
    if (totalWeeks > 4) {
      prevArrow.style.visibility =
        currentBarsStartIndex > 0 ? "visible" : "hidden";
      nextArrow.style.visibility =
        currentBarsStartIndex < maxIndex ? "visible" : "hidden";
    } else {
      prevArrow.style.visibility = "hidden";
      nextArrow.style.visibility = "hidden";
    }

    // Affichage des dates
    if (windowWeeks.length > 0) {
      chartStartEl.textContent = windowWeeks[0].debute_le;
      chartEndEl.textContent = windowWeeks[windowWeeks.length - 1].finit_le;
    }

    // Génération des barres
    const maxVal = Math.max(
      ...windowWeeks.map((r) => Number(r[selectedLevel] || 0)),
      1,
    );
    const containerHeight = barsContainer.clientHeight || 190;
    const maxBarHeight = containerHeight * 0.65;
    const minBarHeight = 6;

    windowWeeks.forEach((row) => {
      const val = Number(row[selectedLevel] || 0);

      const bar = document.createElement("div");
      bar.className = "bar";

      let height = (val / maxVal) * maxBarHeight;
      bar.style.height = `${Math.max(height, minBarHeight)}px`;

      const label = document.createElement("div");
      label.className = "bar-label";
      label.textContent = formatSeconds(val);
      bar.appendChild(label);

      const tooltip = document.createElement("div");
      tooltip.className = "bar-tooltip";
      tooltip.innerHTML = `Du ${row.debute_le}<br>au ${row.finit_le}`;
      bar.appendChild(tooltip);

      barsContainer.appendChild(bar);
    });
  } catch (error) {
    console.error("❌ Erreur updateBars:", error);
  }
}

// ==========================
// START
// ==========================

// Remplir les listes "Récapitulatif par niveau"
function updateRecapLists() {
  const progUl = document.getElementById("progression-list");
  const tempsUl = document.getElementById("temps-list");

  const progressRow = progressionData.find((r) => r.user_id === CURRENT_USER_ID);
  const tempsRow = tempsNiveauData.find((r) => r.user_id === CURRENT_USER_ID);

  if (progUl && progressRow) {
    progUl.innerHTML = LEVEL_COLUMNS
      .filter(l => l !== "-")
      .map(lvl => `<li>${lvl.toUpperCase()} : ${Math.round(Number(progressRow[lvl] || 0))}%</li>`)
      .join("");
  }

  if (tempsUl && tempsRow) {
    tempsUl.innerHTML = LEVEL_COLUMNS
      .filter(l => l !== "-")
      .map(lvl => `<li>${lvl.toUpperCase()} : ${formatSeconds(Number(tempsRow[lvl] || 0))}</li>`)
      .join("");
  }
}

loadData();
loadRegularity();