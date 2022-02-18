<?php

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
if (is_file($logfile)){
    $serverlog = file_get_contents($logfile);
}
$response = ExternalEndpoint::basicCurl($options);
if (empty($response['response'])){
    $msg = <<<HEREDOC
<p>
Der Socket-Server antwortet nicht.
Häufigster Grund: Er ist gerade damit beschäftigt, die Radiodurchsage abzuspielen, und kann nicht auf die Status-Abfrage antworten. Eine nohup-Version ist in Arbeit.
</p>
<p>
Falls das Problem bestehen bleibt: Bitte Prozess raussuchen (ps ax|grep wetter) und mit kill -6 abschießen (nicht: kill -9). Er startet dann automatisch per cron neu.
</p>
<p>Falls auch das nichts hilft: Raspi durchbooten und 2-3 Minuten warten. Es ist alles so eingerichtet, dass nach Systemstart nichts manuell getan werden muss.</p>



HEREDOC;

    echo $msg;
    echo "Letzte bekannte Logdaten:<pre>" .PHP_EOL;
    echo $serverlog;
    die();
}

if (in_array("json", array_keys($_GET))){
    echo $response["response"];
    die();
}
$data = json_decode($response["response"]);

$avgTime = $data->period->timespan / 60;

$tr = [];

foreach ($data->records as $key => $record) {
    $td = [
        "<td>$key</td>",
        "<td>$record->age</td>",


    ];
    foreach ($record->readings as $rkey => $rvalue){
        $td[] = "<td>$rvalue</td>";
    }

    $tdStr = implode('', $td );

    $tr[] = <<<HEREDOC
<tr>
    $tdStr
</tr>

HEREDOC;
}

$trStr = implode(PHP_EOL, $tr );

$lastShortBroadcastAge = time() - $data->last_broadcast_times->short;
$lastFullBroadcastAge = time() - $data->last_broadcast_times->full;
$numRecords = count($data->records);
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
<ul>
<li>Raspberry: Läuft! (Offensichtlich, dieser Webserver läuft nämlich auch drauf...)</li>
<li>Station: Die letzte Messung wurde vor <strong>{$data->records[0]->age}</strong> Sekunden empfangen.</li>
<li>Es befinden sich <strong>$numRecords</strong> von idealerweise 20 Messungen im Arbeitsspeicher</li>
<li>Die letzte <em>kurze</em> Funkdurchsage wurde vor <strong>$lastShortBroadcastAge</strong> Sekunden abgespielt.</li>
<li>Die letzte <em>lange</em> Funkdurchsage wurde vor <strong>$lastFullBroadcastAge</strong> Sekunden abgespielt.</li>
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
<th>Alter (s)</th>
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
