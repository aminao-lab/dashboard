<?php

/**
 * Fonctions utilitaires
 */

/**
 * Logger avec timestamp
 */
function logMessage($message, $level = 'INFO')
{
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}\n";

    echo $logMessage;

    // Écrire dans un fichier log
    $logFile = __DIR__ . '/../logs/sync_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

/**
 * Formater un timestamp Unix en date lisible
 */
function formatTimestamp($timestamp)
{
    if (!$timestamp) return null;
    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * Obtenir le numéro de semaine ISO
 */
function getISOWeek($date = null)
{
    $date = $date ?? new DateTime();
    if (!$date instanceof DateTime) {
        $date = new DateTime($date);
    }
    return $date->format('o-\WW'); // Format: 2025-W01
}

/**
 * Obtenir lundi et dimanche d'une semaine
 */
function getWeekRange($isoWeek = null)
{
    if (!$isoWeek) {
        $isoWeek = getISOWeek();
    }

    list($year, $week) = explode('-W', $isoWeek);

    $dto = new DateTime();
    $dto->setISODate($year, $week);
    $monday = $dto->format('Y-m-d 00:00:00');

    $dto->modify('+6 days');
    $sunday = $dto->format('Y-m-d 23:59:59');

    return ['monday' => $monday, 'sunday' => $sunday];
}

/**
 * Convertir secondes en format lisible
 */
function formatSeconds($seconds)
{
    if ($seconds < 60) {
        return $seconds . 's';
    } elseif ($seconds < 3600) {
        return floor($seconds / 60) . 'min';
    } else {
        $hours = floor($seconds / 3600);
        $mins = floor(($seconds % 3600) / 60);
        return $mins > 0 ? "{$hours}h{$mins}" : "{$hours}h";
    }
}

/**
 * Gérer la progression du batch (stockage dans un fichier)
 */
function getBatchProgress($key)
{
    $file = __DIR__ . '/../logs/batch_' . $key . '.txt';
    if (file_exists($file)) {
        return (int)file_get_contents($file);
    }
    return 0;
}

function setBatchProgress($key, $value)
{
    $file = __DIR__ . '/../logs/batch_' . $key . '.txt';
    file_put_contents($file, $value);
}

function clearBatchProgress($key)
{
    $file = __DIR__ . '/../logs/batch_' . $key . '.txt';
    if (file_exists($file)) {
        unlink($file);
    }
}
