<?php

namespace Schwarzwaldgeier\WetterSocket;

class ExternalEndpoint
{
    public string $baseUrl;
    public array $parameters;
    public string $method;
    public string $token;

    public function __construct($baseUrl, $token = "")
    {
        $this->baseUrl = $baseUrl;
        $this->token = $token;
    }

    public function send(): array
    {
        $paramStr ="";
        foreach ($this->parameters as $key => $value){
            $paramStr .= "$key=$value&";
        }
        $url = $this->baseUrl . '?' . $paramStr;

        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $this->method,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        );

        return ExternalEndpoint::basicCurl($options);

    }

    public static function basicCurl($options): array
    {
        $headers = [];

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_VERBOSE => false,
            CURLOPT_NOPROXY => '*',
        ));
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt_array($curl, $options);


        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errorNumber = curl_errno($curl);
        $errorMsg = curl_error($curl);
        curl_close($curl);

        if ($response === false && $status === 0) {
            error_log(sprintf
            (
                'Unable make curl request! <%s> %s.', $errorNumber, $errorMsg
            ));

        }

        return array('response' => $response, 'status' => $status);
    }

    public function getParamsFromRecord(Record $record){
        $this->parameters =  [
            "wd" => $record->winddirection ?? 0,
            "owd" => $record->uncalibratedWindDirection ?? 0,
            "ws" => $record->windspeed,
            "ows" => $record->windspeed,
            "te" => $record->temperature,
            "pr" => $record->pressure,
            "ms" => $record->windspeedMax,
            "oms" => $record->windspeedMax,
            "hu" => $record->humidity,
            "wc" => $record->windchill,
            "token" => $this->token
        ];
    }
}
