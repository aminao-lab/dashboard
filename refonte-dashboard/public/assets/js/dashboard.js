// ============================================================================
// ÉTAT GLOBAL
// ============================================================================
let currentUserId = null;
let currentNiveau = "";
let dashboardData = {};
let timeChart = null;
let currentWeekIndex = 0;
let filteredWeeks = []; // ✅ Semaines filtrées (sans la première)

// ============================================================================
// INITIALISATION
// ============================================================================
document.addEventListener("DOMContentLoaded", async () => {
  // Récupérer l'user_id depuis l'URL
  const urlParams = new URLSearchParams(window.location.search);
  currentUserId = urlParams.get("user_id");

  if (!currentUserId) {
    showError(
      "❌ Aucun user_id fourni dans l'URL. Exemple : dashboard.html?user_id=xxx",
    );
    return;
  }

  // Charger les données
  await loadDashboard();

  // Event : changement de niveau
  document.getElementById("niveau-select").addEventListener("change", (e) => {
    currentNiveau = e.target.value;
    currentWeekIndex = 0;
    refreshDisplay();
  });

  // Event : navigation semaines
  document.getElementById("prev-week").addEventListener("click", () => {
    if (currentWeekIndex < filteredWeeks.length - 1) {
      currentWeekIndex++;
      displayWeekData();
    }
  });

  document.getElementById("next-week").addEventListener("click", () => {
    if (currentWeekIndex > 0) {
      currentWeekIndex--;
      displayWeekData();
    }
  });
});

// ============================================================================
// CHARGEMENT DES DONNÉES
// ============================================================================
async function loadDashboard() {
  try {
    // 1. Récupérer l'élève
    const { data: student, error: studentError } = await supabase
      .from("students")
      .select("*")
      .eq("user_id", currentUserId)
      .single();

    if (studentError || !student) {
      showError("❌ Élève non trouvé");
      console.error("Erreur student:", studentError);
      return;
    }

    dashboardData.student = student;

    // 2. Récupérer la progression
    const { data: progression } = await supabase
      .from("progression")
      .select("*")
      .eq("user_id", currentUserId)
      .single();

    dashboardData.progression = progression || {};

    // 3. Récupérer le temps total
    const { data: tempsNiveau } = await supabase
      .from("temps_niveau")
      .select("*")
      .eq("user_id", currentUserId)
      .single();

    dashboardData.tempsNiveau = tempsNiveau || {};

    // 4. Récupérer les temps hebdomadaires (toutes les semaines)
    const { data: tempsWeek } = await supabase
      .from("temps_week")
      .select("*")
      .eq("user_id", currentUserId)
      .order("semaine", { ascending: false });

    dashboardData.tempsWeek = tempsWeek || [];

    // ✅ FILTRER : Exclure la PREMIÈRE semaine (la plus ancienne)
    if (dashboardData.tempsWeek.length > 1) {
      // La liste est triée desc (plus récente d'abord)
      // Donc on enlève la DERNIÈRE (qui est la plus ancienne)
      filteredWeeks = dashboardData.tempsWeek.slice(0, -1);
    } else {
      // Si une seule semaine ou aucune, on garde tel quel
      filteredWeeks = dashboardData.tempsWeek;
    }

    console.log("Semaines totales:", dashboardData.tempsWeek.length);
    console.log("Semaines filtrées (sans la 1ère):", filteredWeeks.length);

    // Afficher
    displayStudentName();
    updateLastUpdate();
    refreshDisplay();
  } catch (error) {
    console.error("Erreur chargement:", error);
    showError("Erreur lors du chargement des données");
  }
}

// ============================================================================
// AFFICHAGE
// ============================================================================

/**
 * Afficher le nom de l'élève
 */
function displayStudentName() {
  const { student } = dashboardData;
  const name = student.username || student.email?.split("@")[0] || "Élève";
  document.getElementById("student-name").textContent = name;
}

/**
 * Rafraîchir l'affichage selon le niveau
 */
function refreshDisplay() {
  if (!currentNiveau) {
    // Aucun niveau sélectionné
    document.getElementById("no-level-message").style.display = "block";
    document.getElementById("stats-content").style.display = "none";
  } else {
    // Niveau sélectionné
    document.getElementById("no-level-message").style.display = "none";
    document.getElementById("stats-content").style.display = "block";

    displayMainCards();
    displayWeekData();
    display30DaysChart();
  }
}

/**
 * Afficher les cartes principales
 */
function displayMainCards() {
  const { tempsNiveau, progression } = dashboardData;

  // Temps global
  const tempsSeconds = tempsNiveau[currentNiveau] || 0;
  document.getElementById("temps-global").textContent =
    formatTimeSimple(tempsSeconds);

  // Score
  const score = progression[currentNiveau] || 0;
  document.getElementById("score-global").textContent = `${score}%`;

  const subtitle =
    score === 0
      ? "Vous n'avez pas encore commencé"
      : score === 100
        ? "Félicitations ! Niveau terminé"
        : "En cours...";

  document.getElementById("score-subtitle").textContent = subtitle;
}

/**
 * Afficher les données de la semaine sélectionnée
 */
function displayWeekData() {
  // ✅ Utiliser filteredWeeks au lieu de tempsWeek
  if (!filteredWeeks || filteredWeeks.length === 0) {
    document.getElementById("week-time").textContent = "0 HEURE";
    document.getElementById("week-dates").textContent = "Aucune donnée";
    document.getElementById("prev-week").disabled = true;
    document.getElementById("next-week").disabled = true;
    return;
  }

  const week = filteredWeeks[currentWeekIndex];

  // Temps de la semaine
  const weekSeconds = week[currentNiveau] || 0;
  document.getElementById("week-time").textContent =
    formatTimeWeek(weekSeconds);

  // Dates
  const startDate = formatDate(week.debute_le);
  const endDate = formatDate(week.finit_le);
  document.getElementById("week-dates").textContent =
    `Du ${startDate} au ${endDate}`;

  // Activer/désactiver les boutons
  document.getElementById("prev-week").disabled =
    currentWeekIndex >= filteredWeeks.length - 1;
  document.getElementById("next-week").disabled = currentWeekIndex === 0;
}

/**
 * Afficher le graphique 30 derniers jours
 */
function display30DaysChart() {
  // ✅ Utiliser filteredWeeks
  if (!filteredWeeks || filteredWeeks.length === 0) {
    document.getElementById("chart-wrapper").innerHTML =
      '<p class="no-data">Aucune donnée disponible</p>';
    return;
  }

  // Prendre les 4 dernières semaines (environ 30 jours)
  const last4Weeks = filteredWeeks.slice(0, 4).reverse();

  // Labels (dates de début)
  const labels = last4Weeks.map((w) => {
    const date = w.debute_le.split("-");
    return `${date[2]}/${date[1]}`;
  });

  // Données (temps en heures)
  const data = last4Weeks.map((w) => {
    const seconds = w[currentNiveau] || 0;
    return Math.round((seconds / 3600) * 10) / 10; // Heures avec 1 décimale
  });

  // Détruire le graphique précédent
  if (timeChart) {
    timeChart.destroy();
  }

  // Créer le graphique
  const ctx = document.getElementById("time-chart").getContext("2d");
  timeChart = new Chart(ctx, {
    type: "bar",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Heures",
          data: data,
          backgroundColor: "#ff6b6b",
          borderColor: "#ff6b6b",
          borderWidth: 0,
          borderRadius: 8,
          barThickness: 40,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: false,
        },
        tooltip: {
          backgroundColor: "rgba(0, 0, 0, 0.8)",
          padding: 12,
          titleFont: {
            size: 14,
            weight: "bold",
          },
          bodyFont: {
            size: 13,
          },
          callbacks: {
            label: function (context) {
              const hours = context.parsed.y;
              const mins = Math.round((hours % 1) * 60);
              const h = Math.floor(hours);
              return h > 0
                ? `${h}h${mins.toString().padStart(2, "0")}`
                : `${mins} minutes`;
            },
          },
        },
      },
      scales: {
        x: {
          grid: {
            display: false,
          },
          ticks: {
            font: {
              size: 11,
              weight: "500",
            },
            color: "#666",
          },
        },
        y: {
          beginAtZero: true,
          grid: {
            color: "#f0f0f0",
            drawBorder: false,
          },
          ticks: {
            callback: function (value) {
              return value + "H";
            },
            font: {
              size: 11,
            },
            color: "#666",
          },
        },
      },
    },
  });
}

// ============================================================================
// UTILITAIRES
// ============================================================================

/**
 * Formater le temps (simple) - Ex: 3H19
 */
function formatTimeSimple(seconds) {
  if (seconds === 0) return "0H";

  const hours = Math.floor(seconds / 3600);
  const mins = Math.floor((seconds % 3600) / 60);

  if (hours === 0) return `${mins}MIN`;
  if (mins === 0) return `${hours}H`;
  return `${hours}H${mins.toString().padStart(2, "0")}`;
}

/**
 * Formater le temps pour la semaine - Ex: 3 HEURES
 */
function formatTimeWeek(seconds) {
  if (seconds === 0) return "0 HEURE";

  const hours = Math.floor(seconds / 3600);
  const mins = Math.floor((seconds % 3600) / 60);

  if (hours === 0) {
    return `${mins} MINUTE${mins > 1 ? "S" : ""}`;
  }

  if (mins === 0) {
    return `${hours} HEURE${hours > 1 ? "S" : ""}`;
  }

  return `${hours}H${mins.toString().padStart(2, "0")}`;
}

/**
 * Formater une date - Ex: 05/01/2026
 */
function formatDate(dateStr) {
  const date = new Date(dateStr);
  const day = date.getDate().toString().padStart(2, "0");
  const month = (date.getMonth() + 1).toString().padStart(2, "0");
  const year = date.getFullYear();
  return `${day}/${month}/${year}`;
}

/**
 * Mettre à jour la date
 */
function updateLastUpdate() {
  const now = new Date();
  const formatted = now.toLocaleDateString("fr-FR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  });
  document.getElementById("last-update").textContent = formatted;
}

/**
 * Afficher une erreur
 */
function showError(message) {
  const greeting = document.getElementById("greeting");
  greeting.innerHTML = `<span class="error">${message}</span>`;

  // Masquer le contenu
  document.getElementById("no-level-message").style.display = "none";
  document.getElementById("stats-content").style.display = "none";
}
