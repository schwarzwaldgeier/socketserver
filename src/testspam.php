<?php
$sep = "\x1f\r\n";



$port = 7976;
if (isset($argv[2])){
    $port = $argv[2];
}
$ip = '127.0.0.1';
if (isset($argv[1])){
    $ip = $argv[1];
}
$fp = fsockopen($ip, $port, $errno, $errstr, 30);
$time = new DateTime();
$time->setDate(2000,1,1);
$time->setTime(0,0,0);
while (true) {
    sleep (1);

    if (!$fp) {
        $fp = fsockopen($ip, $port, $errno, $errstr, 30);
    } else {


        /**
         * time_of_read, date_of_read, temperature, pressure, humidity, windspeed_max_original, windspeed_original, \
        windchill, wind_direction_original
         */
        $te = rnd("TE", 0, 35);
        $dr = rnd("DR", 950,1050);
        $fe =rnd("FE", 0, 100);
        $ws = rnd("WS", 0, 30);
        $wd = rnd("WD", 10, 40);
        $wc = rnd("WC", 0,20);
        $wv = (float)rnd("", 340,380);
        if ($wv > 360){
            $wv = -099997.00; //zero wind
            $ws = "WS0.0";
            $wd = "WD0.0";
        }
        $wv = "WV" . $wv;

        $hour = $time->format("H:i:s");;
        $date = $time->format("d.m.y");
        $str = "{$hour}, {$date}, {$te}, {$dr}, {$fe}, {$ws}, {$wd}, {$wc}, {$wv}, $sep";

        $strlen = rnd("", 43, 46);
        $output1 = substr($str, 0, $strlen);
        $output2 = substr($str, $strlen);
        fwrite($fp, $output1);
        fwrite($fp, $output2);

        $time->add(new DateInterval('PT10S'));
        //fclose($fp); // To close the connection
    }
}
function rnd($prefix, $min, $max, $seed = 0){
    srand($seed);
    $number = $min+lcg_value()*(abs($max-$min));
    $number = round($number, 2);
    return $prefix.$number;

}