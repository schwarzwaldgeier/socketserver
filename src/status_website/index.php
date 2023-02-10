<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use Schwarzwaldgeier\WetterSocket\ExternalEndpoint;

require_once("../ExternalEndpoint.php");
$options = array(
    CURLOPT_URL => "http://192.168.111.11:7977/",
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
);

$serverlog = "(nichts gefunden)";
$logfile = "serverlog.txt";
if (is_file($logfile)) {
    $serverlog = file_get_contents($logfile);
}
$response = ExternalEndpoint::basicCurl($options);
if (empty($response['response'])) {
    $msg = <<<HEREDOC
<p>
Der Socket-Server antwortet nicht.
Mit viel Pech hast du die falsche Minisekunde erwischt, und er empfängt gerade Daten von der Station oder baut die Funkdatei. In dem Fall bitte einfach Seite neu laden.</p>
<p>
Falls das Problem bestehen bleibt: Bitte Prozess raussuchen (ps ax|grep wetter) und mit kill -6 abschießen (nicht: kill -9). Er startet dann automatisch per cron neu.
</p>
<p>Falls auch das nichts hilft: Raspi durchbooten und 2-3 Minuten warten. Es ist alles so eingerichtet, dass nach Systemstart nichts manuell getan werden muss.</p>



HEREDOC;

    echo $msg;
    echo "Letzte bekannte Logdaten:<pre>" . PHP_EOL;
    echo $serverlog;
    die();
}

exec("uptime", $uptime);
$free = [];
$socketProcess = shell_exec('(/usr/bin/ps ax | grep wetterstation_socket | grep -v grep)');
exec('free -h --mega', $free);
$free = implode('<br>', $free);

if (in_array("json", array_keys($_GET))) {
    echo $response["response"];
    die();
}
$data = json_decode($response["response"]);

$avgTime = $data->period->timespan / 60;
if (!is_file("Funk.wav")){
    echo "Please create a symlink to the Funk.wav file in the same directory as this script:<br>";
echo "<pre>ln -s /run/wetter_socket/Funk.wav /usr/local/bin/wetterstation_socket/src/status_website/Funk.wav</pre>";
}

$funkwav = "Funk.wav?" . hash_file("md5", "Funk.wav");


$tr = [];


foreach ($data->records as $key => $record) {
    $niceAge = secondsToTime($record->age);
    $td = [
        "<td>$key</td>",
        "<td>$niceAge</td>",
    ];
    foreach ($record->readings as $rkey => $rvalue) {
        $td[] = "<td>$rvalue</td>";
    }

    $tdStr = implode('', $td);

    $tr[] = <<<HEREDOC
<tr>
    $tdStr
</tr>

HEREDOC;
}

$trStr = implode(PHP_EOL, $tr);

$lastShortBroadcastAge = secondsToTime(time() - $data->last_broadcast_times->short);
$lastFullBroadcastAge = secondsToTime(time() - $data->last_broadcast_times->full);
$lastBroadcastText = $data->last_broadcast_times->text;


$numRecords = count($data->records);
$age = $data->records[0]->age ?? 0;
$ageReadable = secondsToTime($age);
$time_since_station_start = 0;
if (isset ($data->records[0])) {
    $time_since_station_start = (int)$data->records[0]->time_since_station_start;
}
$stationUptime = secondsToTime($time_since_station_start - $age);


$output = <<<HEREDOC
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="style.css">



    <title>Schwarzwaldgeier Wetter Server Status</title>

</head>
<body>
<h1>Schwarzwaldgeier Wetterserver</h1>
<h2>Status</h2>
<h3>Station</h3>
<ul>
<li>Die letzte Messung wurde vor <strong>$ageReadable</strong> empfangen.</li>
<li>Zum Zeitpunkt dieser Messung war die Station seit <strong>$stationUptime</strong> in Betrieb.</li>
</ul>
<h3>Funk</h3>
<ul>
<li>Die letzte <em>kurze</em> Funkdurchsage wurde vor <strong>$lastShortBroadcastAge</strong> abgespielt.</li>
<li>Die letzte <em>lange</em> Funkdurchsage wurde vor <strong>$lastFullBroadcastAge</strong> abgespielt.</li>
<li>
Die letzte abgespielte Durchsage klang so: 
<figure>
<figcaption><blockquote>
  <p>"$lastBroadcastText"</p>
</blockquote></figcaption>
<audio controls>

  <source src="$funkwav" type="audio/wav">
Your browser does not support the audio element.
</audio>
</figure></li>
</ul>
<h3>Raspberry</h3>
<ul>
<li>Es befinden sich <strong>$numRecords</strong> von idealerweise 20 Messungen im Arbeitsspeicher</li>
<li>uptime: <code>$uptime[0]</code></li>
<li>Socketserver-Prozess: <code>$socketProcess</code></li>
<li>Speicher:<br><code>$free;</code>

</li>
</ul>
<h2>Werte</h2>
<h3>Durchschnittswerte ($avgTime Minuten)</h3>
<ul>
<li>Windgeschwindigkeit: {$data->period->average_windspeed->windspeed} km/h aus {$data->period->average_windspeed->wind_direction}° ({$data->period->average_windspeed->wind_direction_name})</li>
<li>Stärkste Böe: {$data->period->max_windspeed->windspeed} km/h aus {$data->period->max_windspeed->wind_direction}° ({$data->period->max_windspeed->wind_direction_name}) </li>
</ul>
<h3>Messungen im Speicher:</h3>
<div>
<table>
<tr>
<th>#</th>
<th>Alter</th>
<th>Windgeschwindigkeit</th>
<th>Böe</th>
<th>Windrichtung</th>
<th></th>
<th>Windchill</th>
<th>Temperatur</th>
<th>Luftdruck</th>
<th>Luftfeuchtigkeit</th>
</tr>
    $trStr
</table>
</div>
<h2>Serverlog (zum Anzeigen klicken)</h2>
<details>
<summary>$logfile</summary>
<pre>
$serverlog
</pre>
</details>



</body>
</html>
HEREDOC;

echo $output;

function secondsToTime($inputSeconds): string
{
    $secondsInAMinute = 60;
    $secondsInAnHour = 60 * $secondsInAMinute;
    $secondsInADay = 24 * $secondsInAnHour;

    // Extract days
    $days = floor($inputSeconds / $secondsInADay);

    // Extract hours
    $hourSeconds = $inputSeconds % $secondsInADay;
    $hours = floor($hourSeconds / $secondsInAnHour);

    // Extract minutes
    $minuteSeconds = $hourSeconds % $secondsInAnHour;
    $minutes = floor($minuteSeconds / $secondsInAMinute);

    // Extract the remaining seconds
    $remainingSeconds = $minuteSeconds % $secondsInAMinute;
    $seconds = ceil($remainingSeconds);

    // Format and return
    $timeParts = [];
    $sections = [
        'Tage' => (int)$days,
        'Stunden' => (int)$hours,
        'Minuten' => (int)$minutes,
        'Sekunden' => (int)$seconds,
    ];

    foreach ($sections as $name => $value) {
        if ($value > 0) {
            $timeParts[] = $value . ' ' . $name;
        }
    }

    return implode(', ', $timeParts);
}