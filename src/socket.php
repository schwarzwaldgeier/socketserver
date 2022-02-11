<?php

namespace Schwarzwaldgeier\WetterSocket;

use Exception;
use Navarr\Socket\Exception\SocketException;
use Navarr\Socket\Socket;
use Navarr\Socket\Server;
use function atan2;
use function cos;
use function deg2rad;
use function rad2deg;
use function sin;

$dir = dirname(__FILE__);

require_once "$dir/../vendor/autoload.php";
require_once "Record.php";

class WetterSocket extends Server
{
    const DEFAULT_PORT = 7977;

    protected string $soundDir;
    protected array $records = [];
    private array $buffer = [];
    private int $timestampLastPlaybackShort;
    //private int $intervalShortAnnouncement = 1;
    private int $intervalShortAnnouncement = 5 * 60;

    private int $timestampLastPlaybackFull;
    //private int $intervalFullAnnouncement = 60;
    private int $intervalFullAnnouncement = 60 * 60;


    /**
     * @return float
     */
    public function getSpeedAverage(): float
    {
        $sum = 0.0;

        foreach ($this->records as $measurement) {
            if (isset($measurement->windspeed)) {
                $sum += $measurement->windspeed;
            }
        }
        return $sum / count($this->records);
    }

    public function getStrongestGust()
    {
        $strongest = null;
        foreach ($this->records as $record) {
            if (!($record instanceof Record)){
               continue;
            }

            if ($strongest === null){
                $strongest = $record;
            }

            if ($strongest instanceof Record){
                if ($record->windspeedMax >= $strongest->windspeedMax){
                    $strongest = $record;
                }
            }
        }
        return $strongest;
    }

    public function getDirectionAverage(): int
    {
        $dirs = [];
        foreach ($this->records as $measurement) {
            if (isset($measurement->winddirection)) {
                $dirs[] = $measurement->winddirection;
            }
        }

        if (empty($dirs)) {
            return -1;
        }

        $sinSum = 0;
        $cosSum = 0;
        foreach ($dirs as $value) {
            $sinSum += sin(deg2rad($value));
            $cosSum += cos(deg2rad($value));
        }

        return (((int)rad2deg((int)atan2((int)$sinSum, (int)$cosSum)) + 360) % 360);
    }


    public function __construct($ip = null, $port = self::DEFAULT_PORT)
    {
        parent::__construct($ip, $port);
        $this->addHook(Server::HOOK_CONNECT, array($this, 'onConnect'));
        $this->addHook(Server::HOOK_INPUT, array($this, 'onInput'));
        $this->addHook(Server::HOOK_DISCONNECT, array($this, 'onDisconnect'));

        $dir = dirname(__FILE__);
        $this->soundDir = "$dir/../sound";


        $this->timestampLastPlaybackFull = time();
        $this->timestampLastPlaybackShort = time();
    }


    /** @noinspection PhpUnusedParameterInspection */
    public function onConnect(Server $server, Socket $client, $message)
    {
        echo 'Connection Established', "\n";
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function onInput(Server $server, Socket $client, $message)
    {
        $messages = explode("\n", $message);

        foreach ($messages as $message) {
            $this->buffer[] = $message;
            $bufSize = count($this->buffer);
            if ($bufSize > 2) {
                array_shift($this->buffer);
            }
            $bufSize = count($this->buffer);
            if ($bufSize > 2) { //should never happen but whatever
                $this->buffer = [
                    $this->buffer[$bufSize - 1],
                    $this->buffer[$bufSize - 2],
                ];
            }

            //check if buffer is at the right state, i.e. timestamp is in first message, rest in  second
            $partsFirst = explode(",", $this->buffer[0]);
            if (!isset($this->buffer[1])){
                continue;
            }
            $partsSecond = explode(",", $this->buffer[1]);
            if (!isset($partsFirst[0])) {
                continue;
            }
            if (!isset($partsSecond[0])) {
                continue;
            }

            $partsFirst[0] = trim($partsFirst[0]);

            if (!preg_match("/^\d{2}:\d{2}:\d{2}$/", $partsFirst[0])) {
                continue;
            }


            $compiledMessage = $this->buffer[0] . $this->buffer[1];
            echo "Received: $compiledMessage" . PHP_EOL;
            try {
                $client->write($compiledMessage, strlen($compiledMessage));
            } catch (SocketException $e) {
                echo $e->getMessage();
            }


            $record = new Record($compiledMessage);
            if (!$record->isValid()) {
                continue;
            }
            echo $record;
            $this->sendToGeier($record);




            $this->records[] = $record;
            $recordBufferSize = 20;




            while (count($this->records) > $recordBufferSize) {
                array_shift($this->records);
            }


            $count = count($this->records);
            echo "$count of $recordBufferSize records in buffer" . PHP_EOL;


            $dirAvg = $this->getDirectionAverage();
            if ($dirAvg === -1){
                $directionAverage = "";
            } else {
                $directionAverage = $this->getWindDirectionNicename($dirAvg);
            }

            $speedAverage = $this->getSpeedAverage();

            $recordWithStrongestGust = $this->getStrongestGust();
            $strongestGustSpeed = $recordWithStrongestGust->windspeedMax;
            if (isset($recordWithStrongestGust->winddirection)) {
                $strongestGustNiceDirection = $this->getWindDirectionNicename($recordWithStrongestGust->winddirection);
            } else {
                $strongestGustNiceDirection = ""; //nullwind
            }
            $now = time();

            $timeSinceLastFullPlayback = $now - $this->timestampLastPlaybackFull;
            $timeSinceLastShortPlayback = $now - $this->timestampLastPlaybackShort;

            echo "time since last full message: $timeSinceLastFullPlayback" . PHP_EOL;
            echo "time since last short message: $timeSinceLastShortPlayback" . PHP_EOL;

            if ($timeSinceLastFullPlayback >= $this->intervalFullAnnouncement) {
                if (isset($record->winddirection))
                {
                    $direction = $this->getWindDirectionNicename($record->winddirection);
                } else {
                    $direction = "";
                }
                $message = <<<HEREDOC
hier-ist-die-wetterstation-des-gleitschirmvereins-baden-auf-dem-merkur p3
aktuelle-windmessung $direction $record->windspeed kmh p1
durchschnittlicher-wind-der-letzten-20-minuten p1 $directionAverage p1 $speedAverage kmh p1
staerkste-windboe-der-letzten-20-minuten $strongestGustNiceDirection $strongestGustSpeed kmh p1
tschuess p3
HEREDOC;
                $this->playAnnouncement($message);
                $this->timestampLastPlaybackFull = $now;
            } else {


                if ($timeSinceLastShortPlayback > $this->intervalShortAnnouncement) {
                    if (isset($record->winddirection))
                    {
                        $direction = $this->getWindDirectionNicename($record->winddirection);
                    } else {
                        $direction = "";
                    }
                    $message = <<<HEREDOC
aktuelle-windmessung p3 $direction $record->windspeed 
p3 
staerkste-windboe p1 $strongestGustNiceDirection p1 $strongestGustSpeed 
p3 
durchschnitt p1 $directionAverage p1 $speedAverage p1 kmh 
p3
tschuess
p3
HEREDOC;
                    $this->playAnnouncement($message);
                    $this->timestampLastPlaybackShort = $now;
                }
            }
        }

        //TODO
        /*
         * * send to geier
         * * create sound file
         * * play sound file
         */
    }

    public function getWindDirectionNicename(float $windDirection): string
    {
        $directions = array('n', 'nno', 'no', 'ono', 'o', 'oso', 'so', 'sso', 's',
            'ssw', 'sw', 'wsw', 'w', 'wnw', 'nw', 'nnw', 'n');
        return $directions[round($windDirection / 22.5)];
    }

    public function createSoundArrayFromString(string $messages): array
    {

        $files = [];
        $parts = preg_split('/\s+/', $messages);
        foreach ($parts as $part) {
            $part = trim($part);
            if (is_numeric($part)) {
                $number = (int)$part;
                if ($number <= 99) {
                    try {
                        $files[] = $this->getClosestNumberSoundFile($number);
                    } catch (Exception $e) {
                        error_log($e->getMessage());
                        continue;
                    }
                } else if ($number >= 100 && $number <= 300) {
                    $hundreds = floor($number / 100) * 100;

                    $tens = $number - $hundreds;
                    try {
                        $tensFile = $this->getClosestNumberSoundFile($tens);
                    } catch (Exception $e) {
                        error_log($e->getMessage());
                        continue;
                    }

                    $files[] = $this->soundDir . "/natural/" . $hundreds;
                    $files[] = $tensFile;

                } else {
                    error_log("number too high for sound file");

                }
            } else {
                $file = $this->soundDir . "/natural/" . $part;
                if (!is_file($file)) {
                    error_log("Missing file: $file");
                    continue;
                }

                $files[] = $file;
            }
        }

        return $files;

    }

    /** @noinspection PhpUnusedParameterInspection */
    public function onDisconnect(Server $server, Socket $client, $message)
    {
        echo 'Disconnection', "\n";
    }


    private function playWaveFileArray(array $wavefiles): void
    {


        /**
         * /usr/bin/shnjoin -Oalways -aFUNK -d/var/www/BERGSTATION/ -rnone -q /var/www/BERGSTATION/soundfiles/funk/out-of-order.mus.wav /var/www/BERGSTATION/soundfiles/funk/p0.mus.wav /var/www/BERGSTATION/soundfiles/funk/p0.mus.wav /var/www/BERGSTATION/soundfiles/funk/p0.mus.wav
         */

        $shnArr = ["shnjoin", "-Oalways", "-aFunk", "-d/tmp", "-rnone", "-q"];
        $shnCommand = array_merge($shnArr, $wavefiles);
        $commandStr = implode(" ", $shnCommand);
        exec("$commandStr > /dev/null", $output, $result_code);
        if ($result_code !== 0) {
            error_log("Failed to join wav files");
            foreach ($output as $o){
                error_log($o);
            }
            return;
        }

        echo "Playing: " . PHP_EOL;
        foreach ($wavefiles as $f){
            $base = basename($f);
            if (preg_match("/^p[0-3]$/", $base)){
                continue;
            }
            echo "$base ";
        }
        echo PHP_EOL;

        exec("play /tmp/Funk.wav > /dev/null 2>&1", $out, $result);
        if ($result !== 0){
            error_log("'play' returned exit code $result");
            foreach ($out as $o){
                error_log($o);
            }
        }

    }

    /**
     * @throws Exception
     */
    private function getClosestNumberSoundFile(int $number): string
    {
        for ($i = $number; $i <= 99; $i++) {
            $file = $this->soundDir . "/natural/" . $i;
            if (is_file($file)) {
                return $file;
            }
        }
        throw new Exception("Found no file");
    }

    private function playAnnouncement(string $message): void
    {
        $long = $this->createSoundArrayFromString($message);
        $this->playWaveFileArray($long);

    }

    private function sendToGeier(Record $record): void
    {
        $dir = dirname(__FILE__);
        $secrets = "$dir/endpoints.secret.php";
        if (!is_file($secrets)){
            error_log("Missing config file for sendig to geier");
            return;
        }
        require_once $secrets;
        $geier = new ExternalEndpoint(GEIER);
        $geier->getParamsFromRecord($record);
        $geier->method = "GET";
        $result = $geier->send();

        if ($result["status"] !== 200) {
            error_log("Failed to send to website: " . $result["status"]);
        } else {
            echo "Sent to website. Response was: " . $result["response"] . PHP_EOL;
        }

    }

}

