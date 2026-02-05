// Configuration Supabase (clé publique - pas de risque)
const SUPABASE_URL = "https://bfjdgdetpozrdiqxopfh.supabase.co";
const SUPABASE_ANON_KEY =
  "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImJmamRnZGV0cG96cmRpcXhvcGZoIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjgyODMzMjksImV4cCI6MjA4Mzg1OTMyOX0.3JKX6cSFVglZTTFSHIDeZEZG8S3O2bRJZKn2xlGCR1I";

// Initialiser le client Supabase
const supabase = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

// Mapping des niveaux (pour affichage)
const NIVEAUX_LABELS = {
  "6eme": "6ème",
  "5eme": "5ème",
  "4eme": "4ème",
  "3eme": "3ème",
  "2nde": "2nde",
  "1ere": "1ère",
  term: "Terminale",
  "term-pc": "Term PC",
};

const NIVEAUX = [
  "6eme",
  "5eme",
  "4eme",
  "3eme",
  "2nde",
  "1ere",
  "term",
  "term-pc",
];
