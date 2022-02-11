<?php

namespace Schwarzwaldgeier\WetterSocket;

use DateTime;
use function substr;
use function trim;

class Record
{
    public const WIND_DIRECTION_OFFSET = 16;
    public const TIMM_FACTOR = 0.74;


    public int $secondsSinceStartup;
    public float $temperature;
    public float $pressure;
    public float $humidity;
    public float $windspeedMax;
    public float $windspeedMaxCalibrated;
    public float $winddirection;
    public float $windspeed;
    public float $windspeedCalibrated;
    public float $windchill;
    private array $idToFieldname = [
            "TE" => "temperature",
            "DR" => "pressure",
            "FE" => "humidity",
            "WS" => "windspeedMax",
            "WD" => "windspeed",
            "WC" => "windchill",
            "WV" => "winddirection"
        ];
    public float $uncalibratedWindDirection;

    public function __construct($line)
    {
        $this->parseStationString($line);
    }


    public function __toString(): string
    {
        $uncalibratedDirection = $this->uncalibratedWindDirection ?? "(zero wind)";
        $calibratedDirection = $this->windDirection ?? "(zero wind)";
        return <<<HEREDOC
Windspeed: $this->windspeed km/h
Windspeed calibrated: $this->windspeedCalibrated km/h

Max Windspeed: $this->windspeedMax km/h
Max Windspeed calibrated: $this->windspeedMaxCalibrated km/h

Direction: $uncalibratedDirection
Direction calibrated: $calibratedDirection

Windchill: $this->windchill °C

Time: $this->secondsSinceStartup seconds after station start
Temperature: $this->temperature °C
Pressure: $this->pressure hPa
Humidity: $this->humidity%


HEREDOC;

    }

    public function isValid(): bool
    {
        foreach ($this->idToFieldname as $fieldname){
            if ($fieldname !== 'winddirection' && !isset($this->{$fieldname})){
                error_log("Invalid record: $fieldname missing");
                return false;
            }
        }
        return true;
    }

    private function parseStationString($str)
    {
        /*
         * 22:51:01, 08.02.22, TE9.93, DR1023.6, FE10.5, WS16.46, WD27.26, WC6.82, WV243.58,
22:51:02, 08.02.22, TE21.26, DR1046.95, FE32.07, WS16.34, WD30.25, WC3.09, WV71.6,
22:51:03, 08.02.22, TE12.54, DR981.66, FE27.67, WS35.28, WD33.24, WC7.03, WV8.78,
22:51:04, 08.02.22, TE15.12, DR957.29, FE63.71, WS20.45, WD38.68, WC4.66, WV208.59,
22:51:05, 08.02.22, TE30.59, DR967.9, FE7.96, WS37.52, WD38.58, WC8, WV317.25,
22:51:06, 08.02.22, TE19.12, DR984.32, FE60.67, WS31.55, WD39.77, WC6.76, WV137.38,
         */


        $items = explode(",", $str);
        $time = $items[0];




        $timeParts = explode(":", $time);



        $date = $items[1];

        $dateParts = explode(".", $date);

        $year = 2000 + (int)$dateParts[2];
        $month = (int)$dateParts[1];
        $day = (int)$dateParts[0];


        $hour = (int)$timeParts[0];
        $minute = (int)$timeParts[1];
        $second = (int)$timeParts[2];


        $brokenDate = new DateTime(); //station always resets to 01-01-2000, so it's only useful to get some relative times
        $brokenDate->setTime($hour, $minute, $second);
        $brokenDate->setDate($year, $month, $day);

        $startupDate = new DateTime();
        $startupDate->setDate(2000,1,1);
        $startupDate->setTime(0,0);


        $this->secondsSinceStartup = $brokenDate->getTimestamp() - $startupDate->getTimestamp();

        foreach ($items as $key => $item) {
            if ($key < 2) {
                continue; //timestamp
            }

            $item = trim($item);

            $colname = substr($item, 0, 2);
            $value = substr($item, 2);
            $fieldname = $this->idToFieldname[$colname] ?? false;
            if ($fieldname === false) {
                continue;
            }

            $this->{$fieldname} = (float)$value;
        }

        if (isset($this->winddirection)){
            if ($this->winddirection == -99997 )
            {
                unset ($this->winddirection);
            } else
            {
                $this->uncalibratedWindDirection = $this->winddirection;
                $this->winddirection = ((int)$this->winddirection - self::WIND_DIRECTION_OFFSET) % 360;
            }
        }

        if (isset($this->windspeed)){
            $this->windspeedCalibrated = $this->windspeed * self::TIMM_FACTOR;
        }

        if (isset($this->windspeedMax)){
            $this->windspeedMaxCalibrated = $this->windspeedMax * self::TIMM_FACTOR;
        }
    }



}