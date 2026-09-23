<?php
header('Content-Type: text/plain');
header('Cache-Control: no-cache, must-revalidate');

$configFile = 'config.json';
$defaultStatus = "1"; // Default to ON

if (!file_exists($configFile)) {
    die($defaultStatus);
}

$json = json_decode(file_get_contents($configFile), true);

if (!$json || !isset($json['schedule'])) {
    die($defaultStatus);
}

$schedule = $json['schedule'];

// Ensure variables exist
$activeDays = isset($schedule['active_days']) && is_array($schedule['active_days']) ? $schedule['active_days'] : [];
$startTime = isset($schedule['time_start']) ? $schedule['time_start'] : "00:00";
$endTime = isset($schedule['time_end']) ? $schedule['time_end'] : "23:59";

// If no days are selected, assume they want it off
if (empty($activeDays)) {
    die("0");
}

$currentDay = date('N'); // 1 (Mon) to 7 (Sun)
$currentTime = date('H:i');
$currentDate = date('Y-m-d');

// Check Holidays
$holidays = isset($schedule['holidays']) && is_array($schedule['holidays']) ? $schedule['holidays'] : [];
foreach ($holidays as $holiday) {
    if ($currentDate >= $holiday['start_date'] && $currentDate <= $holiday['end_date']) {
        die("0"); // Off due to holiday
    }
}

// Check Day
if (!in_array((string)$currentDay, $activeDays)) {
    die("0"); // Not an active day
}

// Check Time
if ($currentTime >= $startTime && $currentTime <= $endTime) {
    die("1"); // Active
} else {
    die("0"); // Inactive
}
