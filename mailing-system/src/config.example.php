<?php

// Microsoft Entra / Microsoft Graph
const MS_TENANT_ID = 'VUL_HIER_TENANT_ID_IN';
const MS_CLIENT_ID = 'VUL_HIER_CLIENT_ID_IN';
const MS_CLIENT_SECRET = 'VUL_HIER_CLIENT_SECRET_IN';
const MS_GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';
const MS_GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

// Mail
const MAIL_FROM_ADDRESS = 'verkoop@aertsactionbike.be';
const MAIL_FROM_NAME = 'Aerts Action Bike';
const MAIL_REPLY_TO = 'verkoop@aertsactionbike.be';
const MAIL_LOGO_URL = 'https://www.aertsactionbike.cc/mailing-system/assets/aab-logo.png';

// Afspraaklink
const BOOKING_URL = 'https://outlook.office365.com/book/Verkochtefietsen@aertsactionbike.be/?ismsaljsauthenabled=true';

// Verzendmodus
const INTERNAL_SEND_ENABLED = true;
const EML_FALLBACK_ENABLED = true;

// Interne loginbeveiliging
// Genereer lokaal verschillende willekeurige waarden, bv.:
// php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
const AUTH_SETUP_KEY = 'VUL_HIER_EEN_LANGE_WILLEKEURIGE_SETUP_SLEUTEL_IN';
const AUTH_AUDIT_PEPPER = 'VUL_HIER_EEN_ANDERE_LANGE_WILLEKEURIGE_WAARDE_IN';

// Noodreset adminwachtwoord. Normaal altijd false houden.
// Zet alleen tijdelijk op true, gebruik een aparte willekeurige resetsleutel en zet daarna direct terug op false.
const AUTH_ADMIN_RESET_ENABLED = false;
const AUTH_ADMIN_RESET_KEY = 'VUL_HIER_EEN_APARTE_LANGE_WILLEKEURIGE_RESET_SLEUTEL_IN';

// SQLite blijft lokaal/server-side en wordt niet naar GitHub gepusht.
const AUTH_DB_PATH = __DIR__ . '/../data/auth.sqlite';

// Productie op https://www.aertsactionbike.cc/mailing-system/
const AUTH_COOKIE_PATH = '/mailing-system/';
const AUTH_FORCE_SECURE_COOKIE = true;

// 30 minuten inactiviteit, maximaal 8 uur per login.
const AUTH_IDLE_TIMEOUT = 1800;
const AUTH_ABSOLUTE_TIMEOUT = 28800;
