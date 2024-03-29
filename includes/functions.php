<?php
function getURL($rURL, $rTimeout = 5)
{
    $rUA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.63 Safari/537.36';
    $rContext = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => $rTimeout, 'header' => 'User-Agent: ' . $rUA . "\r\n"],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    return file_get_contents($rURL, false, $rContext);
}

function getURLBase($rURL)
{
    preg_match('/(?:[^\/]*+\/)++/', $rURL, $matches);
    // $info=parse_url($rURL);
    // print_r($info);
    return $matches[0];
}

function systemID()
{
    $mac = shell_exec("ip link | awk '{print $2}'");
    preg_match_all('/([a-z0-9]+):\s+((?:[0-9a-f]{2}:){5}[0-9a-f]{2})/i', $mac, $matches);
    $output = array_combine($matches[1], $matches[2]);
    $cpu_result = shell_exec("cat /proc/cpuinfo | grep model\ name");
    $cpu_result = strstr($cpu_result, "\n", true);
    $output['cpu_model'] = preg_replace('@model name.+?: @', "", $cpu_result);
    $output = json_encode($output);
    $key = hash('sha256', $output, false);
    return $key;
}

function encryptKey($rKey)
{
    global $rAESKey;
    $method = 'AES-256-CBC';
    $key = hash('sha256', $rAESKey, true);
    $iv = openssl_random_pseudo_bytes(16);
    $ciphertext = openssl_encrypt($rKey, $method, $key, OPENSSL_RAW_DATA, $iv);
    $hash = hash_hmac('sha256', $ciphertext . $iv, $key, true);
    return $iv . $hash . $ciphertext;
}

function decryptKey($rKey)
{
    global $rAESKey;
    $method = 'AES-256-CBC';
    $iv = substr($rKey, 0, 16);
    $hash = substr($rKey, 16, 32);
    $ciphertext = substr($rKey, 48);
    $key = hash('sha256', $rAESKey, true);

    if (!hash_equals(hash_hmac('sha256', $ciphertext . $iv, $key, true), $hash))
        return NULL;

    return openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv);
}

function plog($rText)
{
    echo '[' . date('Y-m-d hrKeys:i:s') . '] ' . $rText . " " . PHP_EOL;
}


function updateSegments($rDirectory, $filesHandler, $rSampleSize = 10, $rHex = true, $rSize = 43200, $segmentsMap)
{
    $firstID = $filesHandler->getFirstMappedID();
    $rOutput = '';
    foreach (range($firstID, $rSize) as $rAdd) {
        $rPath = $rDirectory . '/final/' . $rAdd . '.mp4';
        $rOutput .= 'file \'' . $rPath . '\'' . PHP_EOL;
    }
    file_put_contents($rDirectory . '/playlist2.txt', $rOutput);
    rename($rDirectory . '/playlist2.txt', $rDirectory . '/playlist.txt');
}


function decryptSegment($rKey, $rInput, $rOutput, $rServiceName)
{
    global $rMP4Decrypt;
    $rKeyN = explode(':', $rKey);
    switch ($rServiceName) {

        case "mycanal":
        case "movistar":
            $rVideoChannel = $rKeyN[0];
            $rAudioChannel = $rKeyN[0];
            break;
        default:
            $rVideoChannel = $rKeyN[0];
            $rAudioChannel = $rKeyN[0];
    }

    if (count($rKeyN) == 2) {

        if (0 < strpos($rInput, 'video.complete.m4s'))
            $rWait = exec($rMP4Decrypt . ' --key ' . $rVideoChannel . ':' . $rKeyN[1] . ' ' . $rInput . ' ' . $rOutput . ' 2>&1 &');
        #  plog('Desencriptando segmento de video: ' . $rInput.' en '. $rOutput);
        else
            if (0 < strpos($rInput, 'audio.complete.m4s'))
                $rWait = exec($rMP4Decrypt . ' --key ' . $rAudioChannel . ':' . $rKeyN[1] . ' ' . $rInput . ' ' . $rOutput . ' 2>&1 &');
    } else if (count($rKeyN) == 4) {

        if (0 < strpos($rInput, 'video'))
            $rWait = exec($rMP4Decrypt . ' --key ' . $rVideoChannel . ':' . $rKeyN[1] . ' ' . $rInput . ' ' . $rOutput . ' 2>&1 &');


        if (0 < strpos($rInput, 'audio'))
            $rWait = exec($rMP4Decrypt . ' --key ' . $rAudioChannel . ':' . $rKeyN[3] . ' ' . $rInput . ' ' . $rOutput . ' 2>&1 &');

    } else {
        $rWait = exec($rMP4Decrypt . ' --key 1:' . $rKeyN[1] . ' --key 2:' . $rKey[1] . ' ' . $rInput . ' ' . $rOutput . ' 2>&1 &');
    }

    return file_exists($rOutput);
}

function downloadFiles($rList, $rOutput, $rUA, $headers)
{
    global $rAria;
    $rTimeout = count($rList);

    if ($rTimeout < 3)
        $rTimeout = 12;

    if (0 < count($rList)) {
        $rURLs = join("\n", $rList);
        $rTempList = MAIN_DIR . 'tmp/' . md5($rURLs) . '.txt';
        file_put_contents($rTempList, $rURLs);
        if (isset($headers)) {
            $heads = $headers['http']['header'];
            preg_match('@Referer:.+?(.+)@', $heads, $matches);
            if (count($matches) == 2)
                $referer = $matches[1];
        }
        if (strlen($referer))
            $params = ' -x 5 -U "' . $rUA . '" --referer ' . $referer . ' --connect-timeout=3 --timeout=' . $rTimeout . ' -i "' . $rTempList . '" --dir "' . $rOutput . '" 2>&1';
        else
            $params = ' -x 5 -U "' . $rUA . '" --connect-timeout=3 --timeout=' . $rTimeout . ' -i "' . $rTempList . '" --dir "' . $rOutput . '" 2>&1';

        exec($rAria . $params, $rOut, $rRet);
        unlink($rTempList);
    }

    return true;
}

function downloadFilesCurl( $urls_unique,$rOutput,  $additional_curlopts = null, $max_connections = 100 )
{
    // $urls_unique = array_unique($urls_unique);
    $ret = array();
    $mh = curl_multi_init();
    // $workers format: [(int)$ch]=url
    $workers = array();
    $max_connections = min($max_connections, count($urls_unique));
    $unemployed_workers = array();
    for ($i = 0; $i < $max_connections; ++ $i) {
        $unemployed_worker = curl_init();
        if (! $unemployed_worker) {
            throw new \RuntimeException("failed creating unemployed worker #" . $i);
        }
        $unemployed_workers[] = $unemployed_worker;
    }
    unset($i, $unemployed_worker);

    $work = function () use (&$workers, &$unemployed_workers, &$mh, &$ret): void {
        assert(count($workers) > 0, "work() called with 0 workers!!");
        $still_running = null;
        for (;;) {
            do {
                $err = curl_multi_exec($mh, $still_running);
            } while ($err === CURLM_CALL_MULTI_PERFORM);
            if ($err !== CURLM_OK) {
                $errinfo = [
                    "multi_exec_return" => $err,
                    "curl_multi_errno" => curl_multi_errno($mh),
                    "curl_multi_strerror" => curl_multi_strerror($err)
                ];
                $errstr = "curl_multi_exec error: " . str_replace([
                        "\r",
                        "\n"
                    ], "", var_export($errinfo, true));
                throw new \RuntimeException($errstr);
            }
            if ($still_running < count($workers)) {
                // some workers has finished downloading, process them
                // echo "processing!";
                break;
            } else {
                // no workers finished yet, sleep-wait for workers to finish downloading.
                // echo "select()ing!";
                curl_multi_select($mh, 1);
                // sleep(1);
            }
        }
        while (false !== ($info = curl_multi_info_read($mh))) {
            if ($info['msg'] !== CURLMSG_DONE) {
                // no idea what this is, it's not the message we're looking for though, ignore it.
                continue;
            }
            if ($info['result'] !== CURLM_OK) {
                $errinfo = [
                    "effective_url" => curl_getinfo($info['handle'], CURLINFO_EFFECTIVE_URL),
                    "curl_errno" => curl_errno($info['handle']),
                    "curl_error" => curl_error($info['handle']),
                    "curl_multi_errno" => curl_multi_errno($mh),
                    "curl_multi_strerror" => curl_multi_strerror(curl_multi_errno($mh))
                ];
                $errstr = "curl_multi worker error: " . str_replace([
                        "\r",
                        "\n"
                    ], "", var_export($errinfo, true));
                throw new \RuntimeException($errstr);
            }
            $ch = $info['handle'];
            $ch_index = (int) $ch;
            $url = $workers[$ch_index];
            $ret[$url] = curl_multi_getcontent($ch);
            unset($workers[$ch_index]);
            curl_multi_remove_handle($mh, $ch);
            $unemployed_workers[] = $ch;
        }
    };
    $opts = array(
        CURLOPT_URL => '',
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/103.0.0.0 Safari/537.36',
    );

    if (! empty($additional_curlopts)) {
        // i would have used array_merge(), but it does scary stuff with integer keys.. foreach() is easier to reason about
        foreach ($additional_curlopts as $key => $val) {
            $opts[$key] = $val;
        }
    }
    //Corresponding filestream array for each file
    $fstreams = array();
    foreach ($urls_unique as $url) {
        while (empty($unemployed_workers)) {
            $work();
        }
        $new_worker = array_pop($unemployed_workers);
        $opts[CURLOPT_URL] = $url;
        $path     = parse_url($url, PHP_URL_PATH);
        $filename = pathinfo($path, PATHINFO_FILENAME).'.m4s';
        $filepath = $rOutput.$filename;
        $fstreams[$url] = fopen($filepath, 'w');
        $opts[CURLOPT_FILE] = $fstreams[$url];

        if (! curl_setopt_array($new_worker, $opts)) {
            $errstr = "curl_setopt_array failed: " . curl_errno($new_worker) . ": " . curl_error($new_worker) . " " . var_export($opts, true);
            throw new RuntimeException($errstr);
        }
        $workers[(int) $new_worker] = $url;
        curl_multi_add_handle($mh, $new_worker);
    }
    while (count($workers) > 0) {
        $work();
    }
    foreach ($unemployed_workers as $unemployed_worker) {
        curl_close($unemployed_worker);
    }
    curl_multi_close($mh);
    foreach ($fstreams[$url]  as $fstream) {
        fclose($fstream);
    }
    return $ret;
}

function downloadFile($rInput, $rOutput, $rPHP, $rUA, $rOptions = NULL)
{
    if (!$rOptions)
        $rOptions = [
            'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
    $rContext = stream_context_create($rOptions);
    if ($rPHP) {
        file_put_contents($rOutput, file_get_contents($rInput, false, $rContext));
    } else {
        $rWait = exec('curl "' . $rInput . '" --output "' . $rOutput . '"');
    }
    if (file_exists($rOutput) && (0 < filesize($rOutput))) {
        return true;
    }

    return false;
}


function getChannel($rChannel, $service, $crypt = False, $headersContext = null)
{
    $res = null;
    $json = file_get_contents('/home/wvtohls/origin/' . $service . '.json');

    if ($crypt)
        $json = decryptKey($json);

    $json = json_decode($json, 1);

    if (!$headersContext) {
        $rUA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/101.0.0.0 Safari/537.36';
        $headersContext = [
            'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
    }

    $rContext = stream_context_create($headersContext);
    $url = $json[$rChannel]['url'];

    if (0 == strcmp($service, 'sling')) {
        require_once 'slingtv.php';
        $anofut = date('Y') + 2;
        $res = slingMpd($url);
        $res = preg_replace('@Z\/(\d{4,4})@', 'Z/' . $anofut, $res, 1);

    } else {

        $info = get_headers($url, true, $rContext);

        if (array_key_exists('Location', $info) and is_array($info['Location'])) {
            $url = end($info['Location']);
        } else if (array_key_exists('Location', $info)) {
            $url = $info['Location'];
        }
        $res = $url;
        if (array_key_exists('maxvres', $json[$rChannel]) or array_key_exists('audioDelay', $json[$rChannel]) or
                array_key_exists('encodeAudio', $json[$rChannel])){
            $res=array();
            $res['url']=$url;

            if (array_key_exists('audioDelay', $json[$rChannel]))
                $res['audioDelay']=$json[$rChannel]['audioDelay'];
            if (array_key_exists('encodeAudio', $json[$rChannel]))
                $res['encodeAudio']=$json[$rChannel]['encodeAudio'];
        }
    }
    return $res;
}

function getTimeStringFormatted($mediaurl, $startime)
{

    if (false === strpos($mediaurl, '$Time$')) {
        $timeLength = strlen($startime);

        preg_match('@\$Time\%(.+?)d.*\$@', $mediaurl, $matches);
        $padding = $matches[1][0];
        $length = intval(substr($matches[1], 1));
        $result = $startime;
        do {
            $result = $padding . $result;
        } while (strlen($result) < $length);

    } else {
        $result = $startime;
    }
    return $result;
}

function getBaseUrl($url, $headersContext)
{
    $keep = True;
    $rContext = stream_context_create($headersContext);
    do {
        $info = get_headers($url, 1, $rContext);
        if (is_array($info) and array_key_exists('Location', $info)) {
            if (is_array($info['Location'])) {
                $location = end($info['Location']);
            } else
                $location = $info['Location'];
            if (strlen($location))
                $url = $location;
        } else
            $keep = False;
    } while ($keep);
    $urlInfo = parse_url($url);

    preg_match('/(.*\/)/', $urlInfo['path'], $matches);

    $base = $urlInfo['scheme'] . '://' . $urlInfo['host'] . $matches[1];

    return $base;
}

function GetDataCurl($url, $headersContext)
{
    $headers = array();
    foreach (explode("\r\n", $headersContext['http']['header']) as $h)
        $headers[] = $h;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_POST, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function GetData($url, $headersContext)
{
    $rContext = stream_context_create($headersContext);
    return file_get_contents($url, false, $rContext);
}

function getStreamVideoAndAudioPartes($rChannelData, $rLimit, $rServiceName, $rLang, $maxheight, $headersContext = NULL)
{
    global $rMP4dump;

    if (!file_exists(MAIN_DIR . Init . $rServiceName))
        mkdir(MAIN_DIR . Init . $rServiceName, 493, true);
    if (!$headersContext) {
        $rUA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/103.0.0.0 Safari/537.36';
        $headersContext = [
            'http' => ['method' => 'GET',
                'header' => 'User-Agent: ' . $rUA . "\r\n" .
                    'Accept: */*' . "\r\n" .
                    'sec-ch-ua-platform: "Linux"' . "\r\n" .
                    'sec-ch-ua-mobile: ?0' . "\r\n" .
                    'sec-ch-ua: ".Not/A)Brand";v="99", "Google Chrome";v="103", "Chromium";v="103"' . "\r\n" .
                    'Connection: keep-alive' . "\r\n" .
                    'Sec-Fetch-Dest: empty' . "\r\n" .
                    'Sec-Fetch-Mode: cors' . "\r\n" .
                    'Sec-Fetch-Site: none'
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
    }
    //$start = hrtime(true);

    do {
        do {
            $rData = GetData($rChannelData, $headersContext);
            if (!(strpos($rData, '<MPD')))
                usleep(700000);
        } while (!strpos($rData, '<MPD'));

        //       $end = hrtime(true);
        //      $eta = $end - $start;
        //     $eta = $eta / 1e+6; //nanoseconds to milliseconds
        //     plog('La descarga del manifiesto ha tardado ' . $eta . ' milisegundos' . PHP_EOL);

        $rMPD = simplexml_load_string($rData);
        $hasLocation = isset($rMPD->Location);
        if ($hasLocation)
            $rChannelData = $rMPD->Location;

    } while ($hasLocation);

        $rMPD = simplexml_load_string($rData);
        $pathBase = $rMPD->Period->BaseURL;

        $rBaseURL = getBaseUrl($rChannelData, $headersContext);

        if ($pathBase) {
            preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $pathBase, $matches);

            if (!$matches) {

                preg_match_all('@(\.\.\/)+(.+)@', $pathBase, $matches);
                $numBacks = substr_count($pathBase, '../');
                if ($numBacks) {
                    for ($i = 1; $i <= $numBacks + 1; $i++) {
                        $posbar = strrpos($rBaseURL, '/');
                        $rBaseURL = substr($rBaseURL, 0, $posbar);
                    }

                    $rBaseURL = $rBaseURL . '/' . end($matches)[0];
                }
            }
        }

        $rVideoStart = NULL;
        $rAudioStart = NULL;
        $rVideoTemplate = NULL;
        $rIndex = [];       // Tiempos de inicio de cada uno de los segmentos descritos en el manifiesto
        $rPSSH = NULL;
        $rSegmentStart = 0;
        $maxwidth = -1;
        $AdaptationIndex = -1;
        $RepresentationIndex = 0;
        $i = 0;
        $j = 0;
        $newvideos = 0;
        $parsedSegments = array();
        $rObject = [
            'videopssh' => $rPSSH,
            'audiopssh' => $rPSSH,
            'audio' => NULL,
            'audioKID' => NULL,
            'video' => NULL,
            'videoKID' => NULL,
            'segments' => [],
            'add' => 100
        ];

        // Buscando la calidad de video más alta
        $Period = $rMPD->Period[0];
        $maxHeightAllowed = 2160;
        if ($maxheight)
            $maxHeightAllowed = $maxheight;

        foreach ($Period->AdaptationSet as $rAdaptationSet) {
            $j = 0;
            $attributes = $rAdaptationSet->attributes();

            if ((isset($attributes['contentType']) and strval($attributes['contentType']) == 'video/mp4') or
                (isset($attributes['mimeType']) and strval($attributes['mimeType']) == 'video/mp4') or
                (isset($attributes['contentType']) and strval($attributes['contentType']) == 'video') or
                (isset($attributes['mimeType']) and strval($attributes['mimeType']) == 'video')
            ) {
                foreach ($rAdaptationSet->Representation as $rep) {

                    if (isset($rep->attributes()['width'])) {
                        $thisWidth = intval($rep->attributes()['width']->__toString());
                        $thisHeight = intval($rep->attributes()['height']->__toString());

                        if (($thisWidth > $maxwidth) and ($thisHeight <= $maxHeightAllowed)) {
                            $maxwidth = $thisWidth;
                            $AdaptationIndex = $i;
                            $RepresentationIndex = $j;
                        }

                    } else {
                        $AdaptationIndex = $i;
                        $RepresentationIndex = $j;
                    }
                    $j++;
                }

                if (isset($attributes['width'])) {
                    $thisWidth = intval($attributes['width']->__toString());
                    $thisHeight = intval($attributes['height']->__toString());
                    if (($thisWidth > $maxwidth) and ($thisHeight <= $maxHeightAllowed)) {
                        $maxwidth = $thisWidth;
                        $AdaptationIndex = $i;
                    }
                }
            }
            $i++;
        }

        $rAdaptationSet = $Period->AdaptationSet[$AdaptationIndex];

        //gets video pssh and KID from AdaptationSet inside manifest

        $rID = $rAdaptationSet->Representation[$RepresentationIndex]->attributes()['id'];
        if (isset($rAdaptationSet->SegmentTemplate))
            $segmentTemplate = $rAdaptationSet->SegmentTemplate[0];
        else if (isset($rAdaptationSet->Representation[$RepresentationIndex]->SegmentTemplate))
            $segmentTemplate = $rAdaptationSet->Representation[$RepresentationIndex]->SegmentTemplate[0];

        $rVideoTemplate = str_replace('$RepresentationID$', $rID, $segmentTemplate->attributes()['media']);
        $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $segmentTemplate->attributes()['initialization']);
        if (strpos($rInitSegment, '$Bandwidth$')) {
            $bandwith = strval($rAdaptationSet->Representation[$RepresentationIndex]->attributes()['bandwidth']);
            $rInitSegment = str_replace('$Bandwidth$', $bandwith, $rInitSegment);
            $rVideoTemplate = str_replace('$Bandwidth$', $bandwith, $rVideoTemplate);
        }

        foreach ($rAdaptationSet->ContentProtection as $rContentProtection) {
            if ($rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed' or $rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED') {
                preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $rData, $matches);
                if ($matches) {
                    $rObject['videopssh'] = $matches[0];
                    #plog('PSSH: ' . $rPSSH);
                }
            } else if ($rContentProtection->attributes()['schemeIdUri'] == 'urn:mpeg:dash:mp4protection:2011') {
                $namespaces = $rContentProtection->getNamespaces(true);
                if (array_key_exists('cenc', $namespaces) and isset($rContentProtection->attributes($namespaces['cenc'])['default_KID'])) {
                    $kid = $rContentProtection->attributes($namespaces['cenc'])['default_KID']->__toString();
                    $rObject['videoKID'] = str_replace('-', '', $kid);
                }
            }
        }

        if (!isset($rObject['videopssh'])) {
            if (!file_exists(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment)))

                file_put_contents(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment), file_get_contents($rInitSegment, false, stream_context_create($headersContext)));
            $rPSSH_res = shell_exec($rMP4dump . ' --verbosity 3 --format json ' . MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment));
            preg_match('#"data":"\\[(.+?)\\]#', $rPSSH_res, $rPSSH);

            $data = str_replace(' ', '', trim($rPSSH[1], '[]'));

            #    $pssh = base64_encode($hex);
            $rObject['videopssh'] = base64_encode(hex2bin($data));
            if (!strlen($rObject['videoKID'])) {
                preg_match('#"default_KID":"\\[(.+?)\\]#', $rPSSH_res, $vkid);
                $rObject['videoKID'] = str_replace(' ', '', $vkid[1]);
            }
            #plog('PSSH: ' . $rPSSH);
        }
        //Iteramos sobre todos los segmentos de video

        $rObject['video'] = $rInitSegment;
        $segmentTemplateAtts = $segmentTemplate->attributes();
        $timescale = intval($segmentTemplateAtts['timescale']);

        if (1 == count($segmentTemplate->SegmentTimeline->S)) {
            $segment = current($segmentTemplate->SegmentTimeline->S);

            if (isset($segment['d']))
                $d = intval($segment['d']);
            $rObject['add'] = $d / $timescale;

            if (isset($segment['r']) and $segment['r']) {
                $rRepeats = intval($segment['r']) + 1;
            } else
                $rRepeats = 0;

            if ($rLimit > $rRepeats)
                $rLimit = $rRepeats;
            else
                $segmentsToSkip = $rRepeats - $rLimit;
            if (isset($segment['t'])) {
                $rVideoStart = intval($segment['t']);
                $segmentsToSkip = 0;
                $rVideoStart = $rVideoStart + $segmentsToSkip * $d;

                foreach (range(1, $rRepeats) as $rRepeat) {
                    $rObject['segments'][$rVideoStart]['video'] = str_replace('$Time$', $rVideoStart, $rBaseURL . $rVideoTemplate);
                    $rVideoStart += intval($segment['d']);
                    $rVideoStart = getTimeStringFormatted($rVideoTemplate, $rVideoStart);
                }
            }

            if (isset($segmentTemplateAtts['startNumber']) and !(false === strpos($rVideoTemplate, '$Number'))) {

                $startNumber = intval($segmentTemplateAtts['startNumber']);
                if (!isset($rObject['segments']) or !count($rObject['segments'])) {

                    foreach (range(1, $rRepeats) as $rRepeat) {

                        $rObject['segments'][$startNumber]['video'] = str_replace('$Number$', $startNumber, $rBaseURL . $rVideoTemplate);
                        #    print_r('reemplazo 2 es '.$rObject['segments'][$rVideoStart]['video'].PHP_EOL);
                        $startNumber++;
                    }
                } else {
                    $ids = array_keys($rObject['segments']);
                    foreach ($ids as $id) {
                        $rObject['segments'][$id]['video'] = str_replace('$Number$', $startNumber, $rObject['segments'][$id]['video']);
                        #print_r('reemplazo 2* es '.$rObject['segments'][$id]['video'].PHP_EOL);
                        $startNumber++;
                    }
                }
            }
            if (count($rObject['segments']) > $rLimit)
                $rObject['segments'] = array_slice($rObject['segments'], -1 * $rLimit, $rLimit, true);
        } else {

            if (isset($segmentTemplateAtts['timescale']) and !(false === strpos($rVideoTemplate, '$Time'))) {

                foreach ($segmentTemplate->SegmentTimeline->S as $rSegment) {
                    if (isset($rSegment->attributes()['t']))
                        $rVideoStart = intval($rSegment->attributes()['t']);

                    if (isset($rSegment->attributes()['d']))
                        $rObject['add'] = intval($rSegment->attributes()['d']);
                    if (isset($rSegment->attributes()['r'])) {
                        $rRepeats = intval($rSegment->attributes()['r']) + 1;
                    } else
                        $rRepeats = 1;
                    $rVideoStart = getTimeStringFormatted($rVideoTemplate, $rVideoStart);

                    foreach (range(1, $rRepeats) as $rRepeats) {
                        array_push($rIndex, $rVideoStart);
                        if (isset($rObject['segments'][$rVideoStart]['video']) and strlen($rObject['segments'][$rVideoStart]['video'])) {
                            $replace_in = $rObject['segments'][$rVideoStart]['video'];
                        } else
                            $replace_in = $rBaseURL . $rVideoTemplate;
                        $rObject['segments'][$rVideoStart]['video'] = preg_replace('@\$Time.*\$@', $rVideoStart, $replace_in);
                        #    print_r('reemplazo 1 es ' . $rObject['segments'][$rVideoStart]['video'] . PHP_EOL);
                        $rVideoStart += intval($rSegment->attributes()['d']);
                        $rVideoStart = getTimeStringFormatted($rVideoTemplate, $rVideoStart);
                    }
                }

            }
            if (isset($segmentTemplateAtts['startNumber']) and !(false === strpos($rVideoTemplate, '$Number'))) {
                $startNumber = intval($segmentTemplateAtts['startNumber']);
                if (!isset($rObject['segments']) or !count($rObject['segments'])) {
                    foreach ($segmentTemplate->SegmentTimeline->S as $rSegment) {
                        $rRepeats = 1;
                        if (isset($rSegment->attributes()['r']))
                            $rRepeats = intval($rSegment->attributes()['r']) + 1;

                        foreach (range(1, $rRepeats) as $rRepeat) {

                            $rObject['segments'][$startNumber]['video'] = str_replace('$Number$', $startNumber, $rBaseURL . $rVideoTemplate);
                            # print_r('reemplazo 2 es '.$rObject['segments'][$rVideoStart]['video'].PHP_EOL);
                            $startNumber++;
                        }

                    }
                } else {
                    $ids = array_keys($rObject['segments']);
                    foreach ($ids as $id) {
                        $rObject['segments'][$id]['video'] = str_replace('$Number$', $startNumber, $rObject['segments'][$id]['video']);
                        #  print_r('reemplazo 2* es '.$rObject['segments'][$id]['video'].PHP_EOL);
                        $startNumber++;
                    }
                }
            }
            if (count($rObject['segments']) > $rLimit)
                $rObject['segments'] = array_slice($rObject['segments'], -1 * $rLimit, $rLimit, true);
        }


        unset($rAdaptationSet);
        $rLangAvailable = array();

        //looking for audio tracks
        foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet)
            if (($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4') or ($rAdaptationSet->attributes()['contentType'] == 'audio') or
                ($rAdaptationSet->attributes()['contentType'] == 'audio/mp4') or ($rAdaptationSet->attributes()['mimeType'] == 'audio')
            )
                if (isset($rAdaptationSet->attributes()['lang']))
                    array_push($rLangAvailable, $rAdaptationSet->attributes()['lang']);

        if (is_array($rLang))
            foreach ($rLang as $langSearched) {
                plog('Trying to find audio track for language <strong>' . strtoupper($langSearched) . '</strong>...');
                if (in_array($langSearched, $rLangAvailable)) {
                    plog('Audio track for language ' . strtoupper($langSearched) . ' found!');
                    $rLang = $langSearched;
                    break;
                }
            }
        else {
            if (!in_array($rLang, $rLangAvailable)) {
                plog('Couldn\'t find audio track');
                $rLang = $rLangAvailable[0];
                plog('Audio stream set to <strong>' . strtoupper($rLang) . '</strong>');
                if (isset($rLangAvailable[1]))
                    plog('Second audio track is <strong>' . strtoupper($rLangAvailable[1]) . '</strong>');

            } else
                plog('Audio track for language ' . strtoupper($rLang) . ' found!');
        }

        //loops through all Periods inside manifest
        //Populates audio segments in order based on column vector previously created
        foreach ($rMPD->Period as $id => $Period) {
            foreach ($Period->AdaptationSet as $rAdaptationSet) {

                if (((($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4' or $rAdaptationSet->attributes()['contentType'] == 'audio') and $rAdaptationSet->attributes()['lang'] == $rLang) or
                        (($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4' or $rAdaptationSet->attributes()['contentType'] == 'audio') and !isset($rAdaptationSet->attributes()['lang'])))
                    and ((isset($rAdaptationSet->AudioChannelConfiguration) and $rAdaptationSet->AudioChannelConfiguration->attributes()['value'] == '2') or !isset($rAdaptationSet->AudioChannelConfiguration))) {


                    if (isset($rAdaptationSet->SegmentTemplate))
                        $segmentTemplate = $rAdaptationSet->SegmentTemplate[0];
                    else if (isset($rAdaptationSet->Representation[0]->SegmentTemplate))
                        $segmentTemplate = $rAdaptationSet->Representation[0]->SegmentTemplate[0];

                    $rID = $rAdaptationSet->Representation[count($rAdaptationSet->Representation) - 1]->attributes()['id'];

                    $rAudioTemplate = str_replace('$RepresentationID$', $rID, $segmentTemplate->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $segmentTemplate->attributes()['initialization']);
                    if (isset($bandwith) and strpos($rAudioTemplate, '$Bandwidth$')) {
                        $rAudioTemplate = str_replace('$Bandwidth$', $bandwith, $rAudioTemplate);
                        if (strpos($rInitSegment, '$Bandwidth$'))
                            $rInitSegment = str_replace('$Bandwidth$', $bandwith, $rInitSegment);

                    }

                    $rObject['audio'] = $rInitSegment;
                    $segmentTemplateAtts = $segmentTemplate->attributes();

                    if (1 == count($segmentTemplate->SegmentTimeline->S)) {
                        $audioDuration = 1;
                        $segment = current($segmentTemplate->SegmentTimeline->S);
                        if (isset($segment['d']))
                            $audioDuration = intval($segment['d']);
                        $rRepeats = intval($segment['r']) + 1;
                        if (isset($segment['t'])) {
                            $rAudioStart = intval($segment['t']);
                            $segmentsToSkip = $rRepeats - $rLimit;
                            $rAudioStart = $rAudioStart + $segmentsToSkip * $audioDuration;
                        }


                        if (isset($segmentTemplateAtts['startNumber']) and !(false === strpos($rAudioTemplate, '$Number'))) {
                            if ($rLimit > $rRepeats)
                                $rLimit = $rRepeats;
                            $startNumber = intval($segmentTemplateAtts['startNumber']);
                            foreach (range(1, $rLimit) as $rRepeat) {
                                $rObject['segments'][$startNumber]['audio'] = str_replace('$Number$', $startNumber, $rBaseURL . $rAudioTemplate);

                            }
                        } else {

                            $rAudioStart = getTimeStringFormatted($rAudioTemplate, $rAudioStart);
                            foreach ($rObject['segments'] as $rVideoStart => $info) {
                                $rObject['segments'][$rVideoStart]['audio'] = preg_replace('@\$Time.*\$@', $rAudioStart, $rBaseURL . $rAudioTemplate);
                                $rAudioStart += $audioDuration;
                                $rAudioStart = getTimeStringFormatted($rAudioTemplate, $rAudioStart);
                            }

                        }
                    } else {

                        if (isset($segmentTemplateAtts['startNumber']) and !(false === strpos($rAudioTemplate, '$Number'))) {
                            $startNumber = intval($segmentTemplateAtts['startNumber']);

                            foreach ($rObject['segments'] as $id => $info) {
                                $rObject['segments'][$id]['audio'] = str_replace('$Number$', $startNumber, $rBaseURL . $rAudioTemplate);
                                $startNumber++;
                            }

                        } else {

                            $audioSegments = array();
                            foreach ($segmentTemplate->SegmentTimeline->S as $rSegment) {
                                if (isset($rSegment->attributes()['t']))
                                    $rAudioStart = intval($rSegment->attributes()['t']);

                                $rRepeats = 1;
                                if (isset($rSegment->attributes()['r']))
                                    $rRepeats = intval($rSegment->attributes()['r']) + 1;
                                $rAudioStart = getTimeStringFormatted($rAudioTemplate, $rAudioStart);

                                foreach (range(1, $rRepeats) as $rRepeat) {

                                    array_push($audioSegments, preg_replace('@\$Time.*\$@', $rAudioStart, $rBaseURL . $rAudioTemplate));
                                    $rAudioStart += intval($rSegment->attributes()['d']);
                                    $rAudioStart = getTimeStringFormatted($rAudioTemplate, $rAudioStart);
                                }
                            }
                            $audioSegments = array_slice($audioSegments, count($audioSegments) - 1 * $rLimit, $rLimit, true);

                            $currentAudioSegment = current($audioSegments);

                            foreach ($rObject['segments'] as $segmentID => $info) {
                                $rObject['segments'][$segmentID]['audio'] = $currentAudioSegment;
                                $currentAudioSegment = next($audioSegments);
                            }
                        }
                    }
                    if (!isset($rObject['audiopssh'])) {
                        if (!file_exists(MAIN_DIR . Init . $rServiceName . '/' . md5($rObject['audio'])))
                            file_put_contents(MAIN_DIR . Init . $rServiceName . '/' . md5($rObject['audio']), file_get_contents($rObject['audio'], false, stream_context_create($headersContext)));
                        $rPSSH_res = shell_exec($rMP4dump . ' --verbosity 3 --format json ' . MAIN_DIR . Init . $rServiceName . '/' . md5($rObject['audio']));
                        preg_match('#"data":"\\[(.+?)\\]#', $rPSSH_res, $rPSSH);

                        $data = str_replace(' ', '', trim($rPSSH[1], '[]'));

                        #    $pssh = base64_encode($hex);
                        $rObject['audiopssh'] = base64_encode(hex2bin($data));
                        if (!strlen($rObject['audioKID'])) {
                            preg_match('#"default_KID":"\\[(.+?)\\]#', $rPSSH_res, $akid);
                            $rObject['audioKID'] = str_replace(' ', '', $akid[1]);
                        }
                        #plog('PSSH: ' . $rPSSH);
                    }


                    foreach ($rAdaptationSet->ContentProtection as $rContentProtection) {
                        if (strval($rContentProtection->attributes()['schemeIdUri']) == 'urn:mpeg:dash:mp4protection:2011') {
                            $namespaces = $rContentProtection->getNamespaces(true);

                            if (array_key_exists('cenc', $namespaces)) {
                                $kid = $rContentProtection->attributes($namespaces['cenc'])['default_KID']->__toString();

                                $rObject['audioKID'] = str_replace('-', '', $kid);
                            }
                        }
                    }
                    break;
                }
            }
        }
        return $rObject;
}

/**
 * @throws Exception
 */
function iso8601ToSeconds($input)
{
    $duration = new DateInterval($input);
    $hours_to_seconds = $duration->h * 60 * 60;
    $minutes_to_seconds = $duration->i * 60;
    $seconds = $duration->s;
    return $hours_to_seconds + $minutes_to_seconds + $seconds;
}

function getServiceSegmentsDAZNTimeNew($rChannelData, $rLimit, $rServiceName, $rLang)
{

    global $rMP4dump;

    $rObject = [
        'pssh' => NULL,
        'audio' => NULL,
        'video' => NULL,
        'segments' => [],
        'eventDuration' => NULL,
        'add' => 100
    ];

    if (!file_exists(MAIN_DIR . Init . $rServiceName)) {
        mkdir(MAIN_DIR . Init . $rServiceName, 493, true);
    }

    foreach (range(1, 1) as $rRetry) {
        $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
        $rOptions = [
            'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $rContext = stream_context_create($rOptions);
        $rData = file_get_contents($rChannelData, false, $rContext);

        if (strpos($rData, '<MPD') !== false) {
            $rMPD = simplexml_load_string($rData);
            $pathBase = $rMPD->Period->BaseURL;
            if (strlen($pathBase)) {
                $pos = strpos($pathBase, 'http');

                if (!(0 === $pos)) {
                    $baseurl = getURLBase($rChannelData) . $pathBase;
                } else
                    $baseurl = $pathBase;

            } else {
                $baseurl = getURLBase($rChannelData);
            }

            $rBaseURL = $baseurl;
            $rVideoStart = NULL;
            $rAudioStart = NULL;
            $rVideoTemplate = NULL;
            $rPSSH = NULL;

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {

                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' or $rAdaptationSet->attributes()['contentType'] == 'video') {
                    $rID = $rAdaptationSet->Representation[count($rAdaptationSet->Representation) - 1]->attributes()['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    foreach ($rMPD->Period->AdaptationSet[0]->ContentProtection as $rContentProtection) {
                        if ($rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed' or $rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED') {
                            preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $rData, $matches);
                            if ($matches) {
                                $rPSSH = $matches[0];
                                //plog('PSSH: ' . $rPSSH);

                            }
                        }
                    }
                    if (!$rPSSH) {
                        file_put_contents(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment), file_get_contents($rInitSegment, false, $rContext));
                        $rPSSH_res = shell_exec($rMP4dump . ' --verbosity 3 --format json ' . MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment));
                        preg_match('#"data":"\\[(.+?)\\]#', $rPSSH_res, $rPSSH);
                        #$rPSSH = getWVBox(hex_to_base64(str_replace(' ', '', $rPSSH[1])));
                        // plog('PSSH: ' . $rPSSH);
                    }
                    break;
                }
            }
            $rObject['pssh'] = $rPSSH;


            // $rDurationEvent = iso8601ToSeconds(str_replace('.','',$rMPD->attributes()['mediaPresentationDuration']));
            // $rObject['eventDuration'] = $rDurationEvent;


            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' or $rAdaptationSet->attributes()['contentType'] == 'video') {
                    $rID = $rAdaptationSet->Representation[count($rAdaptationSet->Representation) - 1]->attributes()['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    $rObject['video'] = $rInitSegment;

                    foreach ($rAdaptationSet->SegmentTemplate->SegmentTimeline->S as $rSegment) {
                        if (isset($rSegment->attributes()['t'])) {
                            $rVideoStart = $rSegment->attributes()['t'];
                            $rObject['add'] = $rSegment->attributes()['d'];
                        }
                    }
                }
            }

            $rLangAvailable = array();
            plog('Trying to find audio track for language <strong>' . strtoupper($rLang) . '</strong>...');
            //looking for audio tracks
            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4') or ($rAdaptationSet->attributes()['contentType'] == 'audio'))
                    array_push($rLangAvailable, $rAdaptationSet->attributes()['lang']);
            }

            if (!in_array($rLang, $rLangAvailable)) {
                plog('Couldn\'t find audio track');
                $rLang = $rLangAvailable[0];
                plog('Audio stream set to <strong>' . strtoupper($rLang) . '</strong>');
                if (isset($rLangAvailable[1])) {
                    plog('Second audio track is <strong>' . strtoupper($rLangAvailable[1]) . '</strong>');
                }
            } else {
                plog('Audio track found!');
            }

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['lang'] == $rLang) or ($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4' and !isset($rAdaptationSet->attributes()['lang'])) or ($rAdaptationSet->attributes()['contentType'] == 'audio' and !isset($rAdaptationSet->attributes()['lang']))) {
                    $rID = $rAdaptationSet->Representation[0]->attributes()['id'];
                    $rAudioTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    $rObject['audio'] = $rInitSegment;

                    foreach ($rAdaptationSet->SegmentTemplate->SegmentTimeline->S as $rSegment) {
                        if (isset($rSegment->attributes()['t'])) {
                            $rAudioStart = $rSegment->attributes()['t'];
                        }

                        if (isset($rSegment->attributes()['r'])) {
                            $rRepeats = intval($rSegment->attributes()['r']) + 1;
                        } else {
                            $rRepeats = 1;
                        }

                        foreach (range(1, $rRepeats) as $rRepeat) {
                            $rAudioStart += intval($rSegment->attributes()['d']);
                            $rVideoStart += $rObject['add'];
                            $rObject['segments'][$rVideoStart]['audio'] = str_replace('$Time$', $rAudioStart, $rBaseURL . $rAudioTemplate);
                            $rObject['segments'][$rVideoStart]['video'] = str_replace('$Time$', $rVideoStart, $rBaseURL . $rVideoTemplate);
                        }
                    }
                    $rObject['segments'] = array_slice($rObject['segments'], -1 * $rLimit - 10, $rLimit, true);


                }
            }
        }
    }
    return $rObject;
}

function getServiceSegmentsDSTVNew($rChannelData, $rLimit, $rServiceName, $rLang, $rChannel)
{
    global $rMaxSegments;
    global $rMP4dump;

    if (!file_exists(MAIN_DIR . Init . $rServiceName)) {
        mkdir(MAIN_DIR . Init . $rServiceName, 493, true);
    }

    foreach (range(1, 1) as $rRetry) {
        $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
        $rOptions = [
            'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n" . 'X-Forwarded-For:41.246.27.46' . "\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $rContext = stream_context_create($rOptions);
        $rData = file_get_contents($rChannelData, false, $rContext);

        if (strpos($rData, '<MPD') !== false) {
            $rMPD = simplexml_load_string($rData);
            $pathBase = $rMPD->Period->BaseURL;
            if($pathBase)
            {
                preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $pathBase, $matches);
                if(!$matches)
                {
                    $baseurl = getURLBase($rChannelData).$pathBase;
                }
            }else{
                $baseurl = getURLBase($rChannelData);
            }
            $rBaseURL = $baseurl;
            $rVideoStart = NULL;
            $rAudioStart = NULL;
            $rVideoTemplate = NULL;
            $rPSSH = NULL;
            $rSegmentStart = 0;
            $rIDs = [];
            $rSportsIDS = array('SSBlitz', 'SSGrandstand', 'SSPSL', 'SSPremierLeague', 'SSLaLiga', 'SSFootball', 'SSVariety1', 'SSVariety2', 'SSVariety4', 'SSAction', 'SSRugby', 'SSCricket', 'SSGolf', 'SSTennis', 'SSMWWE');
            $rSportsIDS720p = array('SSVariety3', 'SSMotorsport', 'SSMAXimo1');
            //find highest bandwidth
            plog('Looking for highest bandwidth available...');
            foreach($rMPD->Period->AdaptationSet as $rAdaptationSet)
            {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' OR $rAdaptationSet->attributes()['contentType'] == 'video') {
                    foreach($rAdaptationSet->Representation as $id => $rRepresentation)
                    {
                        array_push($rIDs, array('bandwidth' => (string) $rRepresentation->attributes()['bandwidth'], 'id' => (string) $rRepresentation->attributes()['id']));
                        #print_r($rRepresentation);
                    }
                }

            }
            $keys = array_column($rIDs, 'bandwidth');
            array_multisort($keys, SORT_DESC, $rIDs);
            plog('Highest bandwidth is '. ($rIDs[0]['bandwidth']/1000)."k");
            #print_r($rIDs);

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' OR $rAdaptationSet->attributes()['contentType'] == 'video') {
                    #$rID = $rIDs[0]['id'];
                    #$rID = in_array($rChannel, $rSportsIDS720p) ? '' : $rIDs[0]['id'];
                    if(in_array($rChannel, $rSportsIDS)){
                        $rID = 'video=5000000';
                    }else if(in_array($rChannel, $rSportsIDS720p)){
                        $rID = 'video=2800000';
                    }else{
                        $rID = $rIDs[0]['id'];
                    }
                    #$rID = in_array($rChannel, $rSportsIDS) ? 'video=5000000' : (in_array($rChannel, $rSportsIDS720p) ? 'video=2800000' : $rIDs[0]['id']);
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    foreach($rMPD->Period->AdaptationSet[0]->ContentProtection as $rContentProtection)
                    {
                        if($rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed' OR $rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED')
                        {
                            preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $rData, $matches);
                            if($matches)
                            {
                                $rPSSH  = $matches[0];
                                plog('PSSH: ' . $rPSSH);

                            }
                        }
                    }
                    if(!$rPSSH)
                    {
                        file_put_contents(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment), file_get_contents($rInitSegment, false, $rContext));
                        $rPSSH_res = shell_exec($rMP4dump . ' --verbosity 3 --format json ' . MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment));
                        preg_match('#"data":"\\[(.+?)\\]#', $rPSSH_res, $rPSSH);
                        $rPSSH = getWVBox(hex_to_base64(str_replace(' ', '', $rPSSH[1])));
                        plog('PSSH: ' . $rPSSH);
                    }
                    break;
                }
            }

            $rObject = [
                'pssh'     => $rPSSH,
                'audio'    => NULL,
                'video'    => NULL,
                'segments' => [],
                'add'      => 100
            ];

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' OR $rAdaptationSet->attributes()['contentType'] == 'video') {
                    #$rID = $rIDs[0]['id'];
                    if(in_array($rChannel, $rSportsIDS)){
                        $rID = 'video=5000000';
                    }else if(in_array($rChannel, $rSportsIDS720p)){
                        $rID = 'video=2800000';
                    }else{
                        $rID = $rIDs[0]['id'];
                    }
                    #$rID = in_array($rChannel, $rSportsIDS) ? 'video=5000000' : $rIDs[0]['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    $rObject['video'] = $rInitSegment;
                    foreach ($rAdaptationSet->SegmentTemplate->SegmentTimeline->S as $rSegment) {
                        if (isset($rSegment->attributes()['t'])) {
                            $rVideoStart = $rSegment->attributes()['t'];
                            $rObject['add'] = $rSegment->attributes()['d'];
                        }
                        if (isset($rSegment->attributes()['r'])) {
                            $rRepeats = intval($rSegment->attributes()['r']) + 1;
                        }
                        else {
                            $rRepeats = 1;
                        }

                        foreach (range(1, $rRepeats) as $rRepeat) {

                            $rVideoStart += intval($rSegment->attributes()['d']);
                            $rObject['segments'][$rVideoStart]['video'] = str_replace('$Time$', $rVideoStart, $rBaseURL . $rVideoTemplate);
                        }
                    }
                }
            }

            unset($rVideoStart);
            $rSegmentsCount = count($rObject['segments']);
            plog('Segments: '.$rSegmentsCount);
            $rLangAvailable = array();
            plog('Trying to find audio track for language <strong>'.strtoupper($rLang).'</strong>...');
            //looking for audio tracks
            foreach($rMPD->Period->AdaptationSet as $rAdaptationSet){
                if(($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4') OR ($rAdaptationSet->attributes()['contentType'] == 'audio')){
                    array_push($rLangAvailable, $rAdaptationSet->attributes()['lang']);
                }
            }

            if(!in_array($rLang, $rLangAvailable)){
                plog('Couldn\'t find audio track');
                plog('Audio track set to default');
                if($rLangAvailable[0] != ''){
                    $rLang = $rLangAvailable[0];
                    plog('Audio stream set to <strong>'.strtoupper($rLang).'</strong>');
                }
                if(isset($rLangAvailable[1])){
                    plog('Second audio track is <strong>'.strtoupper($rLangAvailable[1]).'</strong>');
                }
            }else{
                plog('Audio track found!');
            }

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['lang'] == $rLang) OR ($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4' AND !isset($rAdaptationSet->attributes()['lang'])) OR ($rAdaptationSet->attributes()['contentType'] == 'audio' AND !isset($rAdaptationSet->attributes()['lang']))) {
                    $rID = $rAdaptationSet->Representation[0]->attributes()['id'];
                    $rAudioTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    $rObject['audio'] = $rInitSegment;

                    foreach ($rAdaptationSet->SegmentTemplate->SegmentTimeline->S as $rSegment) {
                        if (isset($rSegment->attributes()['t'])) {
                            $rAudioStart = $rSegment->attributes()['t'];
                        }

                        if (isset($rSegment->attributes()['r'])) {
                            $rRepeats = intval($rSegment->attributes()['r']) + 1;
                        }
                        else {
                            $rRepeats = 1;
                        }

                        foreach (range(1, $rRepeats) as $rRepeat) {
                            $rAudioStart += intval($rSegment->attributes()['d']);
                            $rVideoStart = array_keys($rObject['segments']);
                            $rObject['segments'][$rVideoStart[$rSegmentStart]]['audio'] = str_replace('$Time$', $rAudioStart, $rBaseURL . $rAudioTemplate);
                            #plog('Index: '.$rSegmentStart);
                            #plog('Segment: '.$rVideoStart[$rSegmentStart]);
                            #plog('  Video segment: '.$rObject['segments'][$rVideoStart[$rSegmentStart]]['video']);
                            #plog('  Audio segment: '.$rObject['segments'][$rVideoStart[$rSegmentStart]]['audio']);
                            #plog('----');
                            $rSegmentStart++;
                        }

                    }

                    if (!$rLimit) {
                        $rLimit = $rMaxSegments;
                    }

                    foreach($rObject['segments'] as $rSegmentPair => $urls)
                    {
                        ksort($rObject['segments'][$rSegmentPair]);
                    }

                    $rObject['segments'] = array_slice($rObject['segments'], -1 * $rLimit, $rLimit, true);
                    return $rObject;

                }
            }
        }
    }
}

function getServiceSegmentsDirectvGo($rChannelData, $rLimit = NULL, $rServiceName, $rLang)
{
    global $rMaxSegments;
    global $rMP4dump;

    if (!file_exists(MAIN_DIR . Init . $rServiceName)) {
        mkdir(MAIN_DIR . Init . $rServiceName, 493, true);
    }

    foreach (range(1, 1) as $rRetry) {
        $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
        $rOptions = [
            'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $rContext = stream_context_create($rOptions);
        $rData = file_get_contents($rChannelData, false, $rContext);

        if (strpos($rData, '<MPD') !== false) {
            $rMPD = simplexml_load_string($rData);
            $pathBase = $rMPD->BaseURL;
            if($pathBase)
            {
                preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $pathBase, $matches);
                if(!$matches)
                {
                    $baseurl = getURLBase($rChannelData).$pathBase;
                }
            }else{
                $baseurl = getURLBase($rMPD->Location);
            }
            $rBaseURL = $pathBase;
            $rVideoStart = NULL;
            $rAudioStart = NULL;
            $rVideoTemplate = NULL;
            $rPSSH = NULL;
            $rIDs = [];

            $rStartTime = strtotime($rMPD->attributes()['availabilityStartTime']);
            $rSegmentDuration = floatval(6);
            $rDelay = floatval(str_replace('S', '', str_replace('PT', '', $rMPD->attributes()['minBufferTime'])));
            $rTime = strtotime($rMPD->attributes()['publishTime']);
            $rElapsed = floatval(iso8601ToSeconds($rMPD->attributes()['timeShiftBufferDepth']));
            #plog($rTime);


            //find highest bandwidth
            plog('Looking for highest bandwidth available...');
            foreach($rMPD->Period->AdaptationSet as $rAdaptationSet)
            {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' OR $rAdaptationSet->attributes()['contentType'] == 'video') {
                    foreach($rAdaptationSet->Representation as $rRepresentation)
                    {
                        array_push($rIDs, array('bandwidth' => (string) $rRepresentation->attributes()['bandwidth'], 'id' => (string) $rRepresentation->attributes()['id']));
                        #print_r($rRepresentation);
                    }
                }

            }
            $keys = array_column($rIDs, 'bandwidth');
            array_multisort($keys, SORT_DESC, $rIDs);
            plog('Highest bandwidth is '. ($rIDs[0]['bandwidth']/1000)."k");
            #print_r($rIDs);

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' OR $rAdaptationSet->attributes()['contentType'] == 'video') {
                    $rID = $rIDs[0]['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['initialization']);
                    foreach($rMPD->Period->AdaptationSet[0]->ContentProtection as $rContentProtection)
                    {
                        if($rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed' OR $rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED')
                        {
                            preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $rData, $matches);
                            if($matches)
                            {
                                $rPSSH  = $matches[0];
                                plog('PSSH: ' . $rPSSH);

                            }
                        }
                    }
                    if(!$rPSSH)
                    {
                        file_put_contents(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment), file_get_contents($rInitSegment, false, $rContext));
                        $rPSSH_res = shell_exec($rMP4dump . ' --verbosity 3 --format json ' . MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment));
                        preg_match('#"data":"\\[(.+?)\\]#', $rPSSH_res, $rPSSH);
                        $rPSSH = getWVBox(hex_to_base64(str_replace(' ', '', $rPSSH[1])));
                        plog('PSSH: ' . $rPSSH);
                    }
                    break;
                }
            }

            $rObject = [
                'pssh'     => $rPSSH,
                'audio'    => NULL,
                'video'    => NULL,
                'segments' => [],
                'add'      => 1
            ];

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' AND $rAdaptationSet->attributes()['codingDependency'] != 'false') OR ($rAdaptationSet->attributes()['contentType'] == 'video' AND $rAdaptationSet->attributes()['codingDependency'] != 'false')) {
                    $rID = $rIDs[0]['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['initialization']);
                    $rVideoStart = intval($rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['startNumber']);
                    $rCurrentNumber = $rVideoStart + ($rElapsed/$rSegmentDuration) - 2*$rLimit;
                    plog($rVideoStart);
                    $rObject['video'] = $rInitSegment;

                }
            }

            $rLangAvailable = array();
            plog('Trying to find audio track for language <strong>'.strtoupper($rLang).'</strong>...');
            //looking for audio tracks
            foreach($rMPD->Period->AdaptationSet as $rAdaptationSet){
                if(($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4') OR ($rAdaptationSet->attributes()['contentType'] == 'audio')){
                    array_push($rLangAvailable, $rAdaptationSet->attributes()['lang']);
                }
            }

            if(!in_array($rLang, $rLangAvailable)){
                plog('Couldn\'t find audio track');
                $rLang = $rLangAvailable[0];
                plog('Audio stream set to <strong>'.strtoupper($rLang).'</strong>');
                if(isset($rLangAvailable[1])){
                    plog('Second audio track is <strong>'.strtoupper($rLangAvailable[1]).'</strong>');
                }
            }else{
                plog('Audio track found!');
            }

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['lang'] == $rLang) OR ($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4' AND !isset($rAdaptationSet->attributes()['lang'])) OR ($rAdaptationSet->attributes()['contentType'] == 'audio' AND !isset($rAdaptationSet->attributes()['lang']))) {
                    $rID = $rAdaptationSet->Representation[0]->attributes()['id'];
                    $rAudioTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['initialization']);
                    $rAudioStart = intval($rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['startNumber']);
                    $rObject['audio'] = $rInitSegment;

                    $rRepeats = $rLimit;

                    foreach (range(1, $rRepeats) as $rRepeat) {
                        $rCurrentNumber += $rObject['add'];
                        $rObject['segments'][$rCurrentNumber]['audio'] = str_replace('$Number$', floor($rCurrentNumber), $rBaseURL . $rAudioTemplate);
                        $rObject['segments'][$rCurrentNumber]['video'] = str_replace('$Number$', floor($rCurrentNumber), $rBaseURL . $rVideoTemplate);
                    }


                    if (!$rLimit) {
                        $rLimit = $rMaxSegments;
                    }

                    $rObject['segments'] = array_slice($rObject['segments'], -1 * $rLimit, $rLimit, true);
                    return $rObject;

                }
            }
        }
    }
}
function getServiceSegmentsNos($rChannelData, $rLimit, $rServiceName, $rLang, $maxheight, $headers = NULL)
{
    global $rMaxSegments;
    global $rMP4dump;

    if (!file_exists(MAIN_DIR . Init . $rServiceName)) {
        mkdir(MAIN_DIR . Init . $rServiceName, 493, true);
    }

    foreach (range(1, 1) as $rRetry) {
        $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
        $rOptions = [
            'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $rContext = stream_context_create($rOptions);
        $rData = file_get_contents($rChannelData, false, $rContext);

        if (strpos($rData, '<MPD') !== false) {
            $rMPD = simplexml_load_string($rData);
            if ($rMPD->Period->BaseURL) {
                preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $rMPD->Period->BaseURL, $matches);
                if (!$matches) {
                    $baseurl = getURLBase($rChannelData) . $rMPD->Period->BaseURL;
                }
            } else if ($rMPD->Location) {
                $baseurl = getURLBase($rMPD->Location);
            } else {
                $baseurl = getURLBase($rChannelData);
            }

            $rObject = [
                'audio' => NULL,
                'video' => NULL,
                'segments' => [],
                'add' => 1,
                'videopssh' => NULL,
                'audiopssh' => NULL
            ];
            $rBaseURL = $baseurl;
            $rVideoStart = NULL;
            $rAudioStart = NULL;
            $rVideoTemplate = NULL;
            $rPSSH = NULL;
            $rIDs = [];

            $rStartTime = strtotime($rMPD->attributes()['availabilityStartTime']);
            $rSegmentDuration = floatval(6);
            $rDelay = floatval(str_replace('S', '', str_replace('PT', '', $rMPD->attributes()['minBufferTime'])));
            $rTime = strtotime($rMPD->attributes()['publishTime']);
            $rElapsed = $rTime - $rStartTime - $rDelay;


            //find highest bandwidth
            plog('Looking for highest bandwidth available...');
            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' or $rAdaptationSet->attributes()['contentType'] == 'video') {
                    foreach ($rAdaptationSet->Representation as $rRepresentation) {
                        array_push($rIDs, array('bandwidth' => (string)$rRepresentation->attributes()['bandwidth'], 'id' => (string)$rRepresentation->attributes()['id']));
                        #print_r($rRepresentation);
                    }
                }

            }
            $keys = array_column($rIDs, 'bandwidth');
            array_multisort($keys, SORT_DESC, $rIDs);
            plog('Highest bandwidth is ' . ($rIDs[0]['bandwidth'] / 1000) . "k");
            #print_r($rIDs);

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' or $rAdaptationSet->attributes()['contentType'] == 'video') {
                    $rID = $rIDs[0]['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['initialization']);
                    foreach ($rMPD->Period->AdaptationSet[0]->ContentProtection as $rContentProtection) {
                        if ($rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed' or $rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED') {
                            preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $rData, $matches);

                            if ($matches) {
                                $rObject['videopssh'] = $matches[0];
                                $rObject['audiopssh'] = $matches[0];
                                plog('PSSH: ' . $rPSSH);

                            }
                        }
                    }
                    if (!$rPSSH) {
                        file_put_contents(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment), file_get_contents($rInitSegment, false, $rContext));
                        $rPSSH_res = shell_exec($rMP4dump . ' --verbosity 3 --format json ' . MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment));
                        preg_match('#"data":"\\[(.+?)\\]#', $rPSSH_res, $rPSSH);
                        plog('PSSH: ' . $rPSSH);
                    }
                    break;
                }
            }


            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' and $rAdaptationSet->attributes()['codingDependency'] != 'false') or ($rAdaptationSet->attributes()['contentType'] == 'video' and $rAdaptationSet->attributes()['codingDependency'] != 'false')) {
                    $rID = $rIDs[0]['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['initialization']);
                    $rVideoStart = intval($rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['startNumber']);
                    $rCurrentNumber = $rVideoStart + ($rElapsed / $rSegmentDuration) - $rLimit;
                    $rObject['video'] = $rInitSegment;

                }
            }

            $rLangAvailable = array();
            plog('Trying to find audio track for language <strong>' . strtoupper($rLang) . '</strong>...');
            //looking for audio tracks
            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4') or ($rAdaptationSet->attributes()['contentType'] == 'audio')) {
                    array_push($rLangAvailable, $rAdaptationSet->attributes()['lang']);
                }
            }

            if (!in_array($rLang, $rLangAvailable)) {
                plog('Couldn\'t find audio track');
                $rLang = $rLangAvailable[0];
                plog('Audio stream set to <strong>' . strtoupper($rLang) . '</strong>');
                if (isset($rLangAvailable[1])) {
                    plog('Second audio track is <strong>' . strtoupper($rLangAvailable[1]) . '</strong>');
                }
            } else {
                plog('Audio track found!');
            }

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['lang'] == $rLang) or ($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4' and !isset($rAdaptationSet->attributes()['lang'])) or ($rAdaptationSet->attributes()['contentType'] == 'audio' and !isset($rAdaptationSet->attributes()['lang']))) {
                    $rID = $rAdaptationSet->Representation[0]->attributes()['id'];
                    $rAudioTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['initialization']);
                    $rAudioStart = intval($rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['startNumber']);
                    $rObject['audio'] = $rInitSegment;

                    $rRepeats = $rLimit;

                    foreach (range(1, $rRepeats) as $rRepeat) {
                        $rCurrentNumber += $rObject['add'];
                        $rObject['segments'][$rCurrentNumber]['audio'] = str_replace('$Number$', floor($rCurrentNumber), $rBaseURL . $rAudioTemplate);
                        $rObject['segments'][$rCurrentNumber]['video'] = str_replace('$Number$', floor($rCurrentNumber), $rBaseURL . $rVideoTemplate);
                    }


                    if (!$rLimit)
                        $rLimit = $rMaxSegments;

                    $rObject['segments'] = array_slice($rObject['segments'], -1 * $rLimit, $rLimit, true);
                    return $rObject;

                }
            }
        }
    }
}

function getServiceSegmentsIzziGo($rChannelData, $rLimit, $rServiceName, $rLang)
{
    global $rMaxSegments;
    global $rMP4dump;

    if (!file_exists(MAIN_DIR . Init . $rServiceName)) {
        mkdir(MAIN_DIR . Init . $rServiceName, 493, true);
    }

    foreach (range(1, 1) as $rRetry) {
        $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
        $rOptions = [
            'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $rContext = stream_context_create($rOptions);
        $rData = file_get_contents($rChannelData, false, $rContext);

        if (strpos($rData, '<MPD') !== false) {
            $rMPD = simplexml_load_string($rData);
            $pathBase = $rMPD->BaseURL;
            if ($pathBase) {
                preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $pathBase, $matches);
                if (!$matches) {
                    $baseurl = getURLBase($rChannelData) . $pathBase;
                }
            } else {
                $baseurl = getURLBase($rMPD->Location);
            }
            $rBaseURL = getURLBase($rChannelData);
            $rVideoStart = NULL;
            $rAudioStart = NULL;
            $rVideoTemplate = NULL;
            $rPSSH = NULL;
            $rIDs = [];


            //find highest bandwidth
            plog('Looking for highest bandwidth available...');
            $higher = 0;
            $Repres = NULL;
            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' or $rAdaptationSet->attributes()['contentType'] == 'video') {
                    foreach ($rAdaptationSet->Representation as $rRepresentation) {
                        $currentBand = $rRepresentation->attributes()['bandwidth'];
                        array_push($rIDs, array('bandwidth' => (string)$currentBand, 'id' => (string)$rRepresentation->attributes()['id']));
                        if ($higher < (integer)$currentBand) {
                            $Repres = $rRepresentation;
                            $higher = (integer)$currentBand;
                        }

                    }
                }

            }

            $keys = array_column($rIDs, 'bandwidth');
            array_multisort($keys, SORT_DESC, $rIDs);
            plog('Highest bandwidth is ' . ($rIDs[0]['bandwidth'] / 1000) . "k");


            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' or $rAdaptationSet->attributes()['contentType'] == 'video') {
                    $rID = $rIDs[0]['id'];

                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $Repres->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $Repres->SegmentTemplate[0]->attributes()['initialization']);
                    foreach ($rMPD->Period->AdaptationSet[0]->ContentProtection as $rContentProtection) {
                        if ($rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed' or $rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED') {
                            preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $rData, $matches);
                            if ($matches) {
                                $rPSSH = $matches[0];
                                plog('PSSH: ' . $rPSSH);

                            }
                        }
                    }

                    break;
                }
            }

            $rObject = [
                'pssh' => $rPSSH,
                'audio' => NULL,
                'video' => NULL,
                'segments' => [],
                'add' => 1
            ];

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' and $rAdaptationSet->attributes()['codingDependency'] != 'false') or ($rAdaptationSet->attributes()['contentType'] == 'video' and $rAdaptationSet->attributes()['codingDependency'] != 'false')) {
                    $rID = $rIDs[0]['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $Repres->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $Repres->SegmentTemplate[0]->attributes()['initialization']);
                    $rVideoStart = intval($Repres->SegmentTemplate[0]->attributes()['startNumber']);
                    #$rCurrentNumber = $rVideoStart + ($rElapsed/$rSegmentDuration) - $rLimit;
                    $rCurrentNumber = $rVideoStart;
                    plog($rVideoStart);
                    $rObject['video'] = $rInitSegment;

                }
            }

            $rLangAvailable = array();
            plog('Trying to find audio track for language <strong>' . strtoupper($rLang) . '</strong>...');
            //looking for audio tracks
            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4') or ($rAdaptationSet->attributes()['contentType'] == 'audio')) {
                    array_push($rLangAvailable, $rAdaptationSet->attributes()['lang']);
                }
            }

            if (!in_array($rLang, $rLangAvailable)) {
                plog('Couldn\'t find audio track');
                $rLang = $rLangAvailable[0];
                plog('Audio stream set to <strong>' . strtoupper($rLang) . '</strong>');
                if (isset($rLangAvailable[1])) {
                    plog('Second audio track is <strong>' . strtoupper($rLangAvailable[1]) . '</strong>');
                }
            } else {
                plog('Audio track found!');
            }

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['lang'] == $rLang) or ($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4' and !isset($rAdaptationSet->attributes()['lang'])) or ($rAdaptationSet->attributes()['contentType'] == 'audio' and !isset($rAdaptationSet->attributes()['lang']))) {
                    $rID = $rAdaptationSet->Representation[0]->attributes()['id'];
                    $rAudioTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['initialization']);
                    $rAudioStart = intval($rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['startNumber']);
                    $rObject['audio'] = $rInitSegment;

                    $rRepeats = $rLimit;

                    foreach (range(1, $rRepeats) as $rRepeat) {
                        $rCurrentNumber += $rObject['add'];
                        $rObject['segments'][$rCurrentNumber]['audio'] = str_replace('$Number$', floor($rCurrentNumber), $rBaseURL . $rAudioTemplate);
                        $rObject['segments'][$rCurrentNumber]['video'] = str_replace('$Number$', floor($rCurrentNumber), $rBaseURL . $rVideoTemplate);
                    }


                    if (!$rLimit) {
                        $rLimit = $rMaxSegments;
                    }

                    $rObject['segments'] = array_slice($rObject['segments'], -1 * $rLimit, $rLimit, true);
                    return $rObject;
                }
            }
        }
    }
}

function getServiceSegmentsRogersIgnite($rChannelData, $rLimit, $rServiceName, $rLang)
{
    global $rMaxSegments;
    global $rMP4dump;

    if (!file_exists(MAIN_DIR . Init . $rServiceName)) {
        mkdir(MAIN_DIR . Init . $rServiceName, 493, true);
    }

    foreach (range(1, 1) as $rRetry) {
        $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
        $rOptions = [
            'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $rContext = stream_context_create($rOptions);
        $rData = file_get_contents($rChannelData, false, $rContext);

        if (strpos($rData, '<MPD') !== false) {
            $rMPD = simplexml_load_string($rData);
            #$rBaseURL = 'http://localhost/ignitetv/proxy.php?url='.getURLBase($rChannelData);
            $rBaseURL = getURLBase($rChannelData);
            $rVideoStart = NULL;
            $rAudioStart = NULL;
            $rVideoTemplate = NULL;
            $rPSSH = NULL;
            $rIDs = [];

            $rStartTime = strtotime($rMPD->attributes()['availabilityStartTime']);
            $rSegmentDuration = floatval(2);
            $rDelay = floatval(str_replace('S', '', str_replace('PT', '', $rMPD->attributes()['minBufferTime'])));
            $rTime = strtotime($rMPD->attributes()['publishTime']);
            $rElapsed = floatval(iso8601ToSeconds('PT' . ceil(intval(str_replace(array('PT', 'S'), '', $rMPD->attributes()['timeShiftBufferDepth']))) . 'S'));
            #plog($rTime);


            //find highest bandwidth
            plog('Looking for highest bandwidth available...');
            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' or $rAdaptationSet->attributes()['contentType'] == 'video') {
                    foreach ($rAdaptationSet->Representation as $rRepresentation) {
                        array_push($rIDs, array('bandwidth' => (string)$rRepresentation->attributes()['bandwidth'], 'id' => (string)$rRepresentation->attributes()['id']));
                        #print_r($rRepresentation);
                    }
                }

            }
            $keys = array_column($rIDs, 'bandwidth');
            array_multisort($keys, SORT_DESC, $rIDs);
            plog('Highest bandwidth is ' . ($rIDs[0]['bandwidth'] / 1000) . "k");


            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' or $rAdaptationSet->attributes()['contentType'] == 'video') {
                    $rID = $rIDs[0]['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    foreach ($rMPD->Period->AdaptationSet[0]->ContentProtection as $rContentProtection) {
                        if ($rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed' or $rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED') {
                            preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $rData, $matches);
                            if ($matches) {
                                $rPSSH = $matches[0];
                                plog('PSSH: ' . $rPSSH);

                            }
                        }
                    }
                    if (!$rPSSH) {
                        file_put_contents(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment), file_get_contents($rInitSegment, false, $rContext));
                        $rPSSH_res = shell_exec($rMP4dump . ' --verbosity 3 --format json ' . MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment));
                        preg_match('#"data":"\\[(.+?)\\]#', $rPSSH_res, $rPSSH);
                        $rPSSH = getWVBox(hex_to_base64(str_replace(' ', '', $rPSSH[1])));
                        plog('PSSH: ' . $rPSSH);
                    }
                    break;
                }
            }

            $rObject = [
                'pssh' => $rPSSH,
                'audio' => NULL,
                'video' => NULL,
                'segments' => [],
                'add' => 1
            ];

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' and $rAdaptationSet->attributes()['codingDependency'] != 'false') or ($rAdaptationSet->attributes()['contentType'] == 'video' and $rAdaptationSet->attributes()['codingDependency'] != 'false')) {
                    $rID = $rIDs[0]['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    $rVideoStart = intval($rAdaptationSet->SegmentTemplate[0]->attributes()['startNumber']);
                    #$rCurrentNumber = $rVideoStart + ($rElapsed/$rSegmentDuration) - $rLimit;
                    $rCurrentNumber = $rVideoStart + ($rElapsed / $rSegmentDuration) + $rLimit / 2;
                    #plog($rVideoStart);
                    $rObject['video'] = $rInitSegment;

                }
            }

            $rLangAvailable = array();
            plog('Trying to find audio track for language <strong>' . strtoupper($rLang) . '</strong>...');
            //looking for audio tracks
            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4') or ($rAdaptationSet->attributes()['contentType'] == 'audio')) {
                    array_push($rLangAvailable, $rAdaptationSet->attributes()['lang']);
                }
            }

            if (!in_array($rLang, $rLangAvailable)) {
                plog('Couldn\'t find audio track');
                $rLang = $rLangAvailable[0];
                plog('Audio stream set to <strong>' . strtoupper($rLang) . '</strong>');
                if (isset($rLangAvailable[1])) {
                    plog('Second audio track is <strong>' . strtoupper($rLangAvailable[1]) . '</strong>');
                }
            } else {
                plog('Audio track found!');
            }

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['lang'] == $rLang) and ($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4') and ($rAdaptationSet->attributes()['id'] == '5')) {
                    $rID = $rAdaptationSet->Representation[0]->attributes()['id'];
                    $rAudioTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    $rAudioStart = intval($rAdaptationSet->SegmentTemplate[0]->attributes()['startNumber']);
                    $rObject['audio'] = $rInitSegment;

                    $rRepeats = $rLimit;

                    foreach (range(1, $rRepeats) as $rRepeat) {
                        $rCurrentNumber += $rObject['add'];
                        $rObject['segments'][$rCurrentNumber]['audio'] = str_replace('$Number$', floor($rCurrentNumber), $rBaseURL . $rAudioTemplate);
                        $rObject['segments'][$rCurrentNumber]['video'] = str_replace('$Number$', floor($rCurrentNumber), $rBaseURL . $rVideoTemplate);
                    }


                    if (!$rLimit) {
                        $rLimit = $rMaxSegments;
                    }

                    $rObject['segments'] = array_slice($rObject['segments'], -1 * $rLimit, $rLimit, true);
                    return $rObject;

                }
            }
        }
    }
}

function getServiceSegmentsNosPlay($rChannelData, $rLimit = NULL, $rServiceName, $rLang)
{
    global $rMaxSegments;
    global $rMP4dump;

    if (!file_exists(MAIN_DIR . Init . $rServiceName)) {
        mkdir(MAIN_DIR . Init . $rServiceName, 493, true);
    }

    foreach (range(1, 1) as $rRetry) {
        $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
        $rOptions = [
            'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $rContext = stream_context_create($rOptions);
        $rData = file_get_contents($rChannelData, false, $rContext);

        if (strpos($rData, '<MPD') !== false) {
            $rMPD = simplexml_load_string($rData);
            $pathBase = $rMPD->Period->BaseURL;
            if ($pathBase) {
                preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $pathBase, $matches);
                if (!$matches) {
                    $baseurl = getURLBase($rChannelData) . $pathBase;
                }
            } else if ($rMPD->Location) {
                $baseurl = getURLBase($rMPD->Location);
            } else
                $baseurl = getURLBase($rChannelData);
            $rBaseURL = $baseurl;
            $rVideoStart = NULL;
            $rAudioStart = NULL;
            $rVideoTemplate = NULL;
            $rPSSH = NULL;
            $rIDs = [];
            $rObject = [
                'videopssh' => NULL,
                'audiopssh' => NULL,
                'audio' => NULL,
                'video' => NULL,
                'segments' => [],
                'add' => 1
            ];

            $rStartTime = strtotime($rMPD->attributes()['availabilityStartTime']);
            $rSegmentDuration = floatval(4);
            $rDelay = floatval(str_replace('S', '', str_replace('PT', '', $rMPD->attributes()['minBufferTime'])));
            if ($rMPD->UTCTiming)
                $rTime = strtotime($rMPD->UTCTiming->attributes()['value']);
            else
                $rTime = time();
            $rElapsed = $rTime - $rStartTime - $rDelay;


            //find highest bandwidth
            plog('Looking for highest bandwidth available...');
            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' or $rAdaptationSet->attributes()['contentType'] == 'video') {
                    foreach ($rAdaptationSet->Representation as $rRepresentation) {
                        array_push($rIDs, array('bandwidth' => (string)$rRepresentation->attributes()['bandwidth'], 'id' => (string)$rRepresentation->attributes()['id']));
                        #print_r($rRepresentation);
                    }
                }

            }
            $keys = array_column($rIDs, 'bandwidth');
            array_multisort($keys, SORT_DESC, $rIDs);
            plog('Highest bandwidth is ' . ($rIDs[0]['bandwidth'] / 1000) . "k");
            #print_r($rIDs);

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' or $rAdaptationSet->attributes()['contentType'] == 'video') {
                    $rID = $rIDs[0]['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['initialization']);
                    foreach ($rMPD->Period->AdaptationSet[0]->ContentProtection as $rContentProtection) {
                        if ($rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed' or $rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED') {
                            preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $rData, $matches);
                            if ($matches) {
                                $rPSSH = $matches[0];
                                plog('PSSH: ' . $rPSSH);

                            }
                        }
                    }
                    if (!isset($rObject['videopssh'])) {
                        if (!file_exists(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment)))
                            file_put_contents(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment), file_get_contents($rInitSegment, false, $rContext));
                        $rPSSH_res = shell_exec($rMP4dump . ' --verbosity 3 --format json ' . MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment));
                        preg_match('#"data":"\\[(.+?)\\]#', $rPSSH_res, $rPSSH);

                        $data = str_replace(' ', '', trim($rPSSH[1], '[]'));

                        #    $pssh = base64_encode($hex);
                        $rObject['videopssh'] = base64_encode(hex2bin($data));
                        if (!strlen($rObject['videoKID'])) {
                            preg_match('#"default_KID":"\\[(.+?)\\]#', $rPSSH_res, $vkid);
                            $rObject['videoKID'] = str_replace(' ', '', $vkid[1]);
                        }
                        #plog('PSSH: ' . $rPSSH);
                    }
                    break;
                }
            }


            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' and $rAdaptationSet->attributes()['codingDependency'] != 'false') or ($rAdaptationSet->attributes()['contentType'] == 'video' and $rAdaptationSet->attributes()['codingDependency'] != 'false')) {
                    $rID = $rIDs[0]['id'];
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['initialization']);
                    $rVideoStart = intval($rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['startNumber']);
                    $rCurrentNumber = $rVideoStart + ($rElapsed / $rSegmentDuration) - $rLimit;
                    $rObject['video'] = $rInitSegment;

                }
            }

            $rLangAvailable = array();
            plog('Trying to find audio track for language <strong>' . strtoupper($rLang) . '</strong>...');
            //looking for audio tracks
            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4') or ($rAdaptationSet->attributes()['contentType'] == 'audio')) {
                    array_push($rLangAvailable, $rAdaptationSet->attributes()['lang']);
                }
            }

            if (!in_array($rLang, $rLangAvailable)) {
                plog('Couldn\'t find audio track');
                $rLang = $rLangAvailable[0];
                plog('Audio stream set to <strong>' . strtoupper($rLang) . '</strong>');
                if (isset($rLangAvailable[1])) {
                    plog('Second audio track is <strong>' . strtoupper($rLangAvailable[1]) . '</strong>');
                }
            } else {
                plog('Audio track found!');
            }

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['lang'] == $rLang) or ($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4' and !isset($rAdaptationSet->attributes()['lang'])) or ($rAdaptationSet->attributes()['contentType'] == 'audio' and !isset($rAdaptationSet->attributes()['lang']))) {
                    $rID = $rAdaptationSet->Representation[0]->attributes()['id'];
                    $rAudioTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['initialization']);
                    $rAudioStart = intval($rAdaptationSet->Representation->SegmentTemplate[0]->attributes()['startNumber']);
                    $rObject['audio'] = $rInitSegment;

                    $rRepeats = $rLimit;

                    if (!isset($rObject['audiopssh'])) {
                        if (!file_exists(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment)))
                            file_put_contents(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment), file_get_contents($rInitSegment, false, $rContext));
                        $rPSSH_res = shell_exec($rMP4dump . ' --verbosity 3 --format json ' . MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment));
                        preg_match('#"data":"\\[(.+?)\\]#', $rPSSH_res, $rPSSH);

                        $data = str_replace(' ', '', trim($rPSSH[1], '[]'));

                        #    $pssh = base64_encode($hex);
                        $rObject['videopssh'] = base64_encode(hex2bin($data));
                        if (!strlen($rObject['videoKID'])) {
                            preg_match('#"default_KID":"\\[(.+?)\\]#', $rPSSH_res, $akid);
                            $rObject['audioKID'] = str_replace(' ', '', $akid[1]);
                        }
                        #plog('PSSH: ' . $rPSSH);
                    }

                    foreach (range(1, $rRepeats) as $rRepeat) {
                        $rCurrentNumber += $rObject['add'];
                        $rObject['segments'][$rCurrentNumber]['audio'] = str_replace('$Number$', floor($rCurrentNumber), $rBaseURL . $rAudioTemplate);
                        $rObject['segments'][$rCurrentNumber]['video'] = str_replace('$Number$', floor($rCurrentNumber), $rBaseURL . $rVideoTemplate);
                    }


                    if (!$rLimit) {
                        $rLimit = $rMaxSegments;
                    }

                    $rObject['segments'] = array_slice($rObject['segments'], -1 * $rLimit, $rLimit, true);
                    return $rObject;

                }
            }
        }
    }
}

function getStreamParsed($rMPD, $rLimit, $rServicename)
{
    $rSegments = [
        'pssh' => NULL,
        'audio' => NULL,
        'video' => NULL,
        'segments' => [],
        'expires' => NULL
    ];
    if (!file_exists(MAIN_DIR . Init . $rServicename)) {
        mkdir(MAIN_DIR . Init . $rServicename, 493, true);
    }

    $salida = exec("/home/wvtohls/bin/parser '" . $rMPD . "'");
    $salida = json_decode($salida, 1);

    $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
    $rOptions = [
        'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    $rContext = stream_context_create($rOptions);
    $rData = file_get_contents($rMPD, false, $rContext);
    if (strpos($rData, '<MPD') !== false)
        $rMPD = simplexml_load_string($rData);


    /*    $rStartTime = strtotime($rMPD->attributes()['availabilityStartTime']);

        if (!$rStartTime) {
            $rStartTime = strtotime(str_replace(':00Z', ':09Z', $rMPD->attributes()['publishTime']));
        }

        $rSegmentDuration = floatval(str_replace('S', '', str_replace('PT', '', $rMPD->attributes()['maxSegmentDuration'])));
        $rDelay = 0;
        $rElapsed = time() - $rStartTime - $rDelay;

        if ($rElapsed < 0)
            $rElapsed = 15;*/


    foreach ($rMPD->Period as $rPeriod) {
        //     $rPeriodStart = floatval(str_replace('S', '', str_replace('PT', '', $rPeriod->attributes()['start'])));

        //    if ($rPeriodStart <= $rElapsed)
        {
            /* $rPeriodElapsed = $rElapsed - $rPeriodStart;
             $rDuration = floatval(str_replace('S', '', str_replace('PT', '', $rPeriod->attributes()['duration'])));
             $rStartNumber = intval($rPeriod->AdaptationSet[0]->SegmentTemplate->attributes()['startNumber']);
             $rSegments['expires'] = $rStartTime + $rDuration;
             $rCurrentSegment = floor($rStartNumber + ($rPeriodElapsed / $rSegmentDuration));
             $rBaseURL = $rPeriod->BaseURL[0];
             $rPSSH = NULL;*/

            foreach ($rPeriod->AdaptationSet[0]->ContentProtection as $rContentProtection) {
                if ($rContentProtection->attributes()->schemeIdUri == 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed') {
                    list($rPSSH) = explode('</cenc:pssh>', explode('<cenc:pssh>', $rContentProtection->asXML())[1]);

                    if ($rPSSH) {
                        $rSegments['pssh'] = $rPSSH;
                    }
                } else if ($rContentProtection->attributes()['schemeIdUri'] == 'urn:mpeg:dash:mp4protection:2011') {
                    $namespaces = $rContentProtection->getNamespaces(true);
                    if (array_key_exists('cenc', $namespaces) and isset($rContentProtection->attributes($namespaces['cenc'])['default_KID'])) {
                        $kid = $rContentProtection->attributes($namespaces['cenc'])['default_KID']->__toString();
                        $rSegments['videoKID'] = str_replace('-', '', $kid);
                        $rSegments['audioKID'] = str_replace('-', '', $kid);
                    }
                }
            }
        }
    }
    $rSegments['audio'] = $salida['audio'][0]['map']['resolvedUri'];
    $rSegments['video'] = $salida['video'][0]['map']['resolvedUri'];
    $salida['audio'] = array_slice($salida['audio'], -1 * $rLimit, $rLimit, false);
    $salida['video'] = array_slice($salida['video'], -1 * $rLimit, $rLimit, false);
    foreach (range(0, $rLimit - 1) as $val) {
        $segmentID = time() + $val;
        $rSegments['segments'][$segmentID]['audio'] = $salida['audio'][$val]['resolvedUri'];
        $rSegments['segments'][$segmentID]['video'] = $salida['video'][$val]['resolvedUri'];
    }

    $rSegments['segments'] = array_slice($rSegments['segments'], -1 * $rLimit, $rLimit, true);
    return $rSegments;
}

function getServiceSegmentsVoomotion($rChannelData, $rLimit = NULL, $rServiceName, $rLang)
{
    global $rMaxSegments;
    global $rMP4dump;

    if (!file_exists(MAIN_DIR . Init . $rServiceName)) {
        mkdir(MAIN_DIR . Init . $rServiceName, 493, true);
    }

    foreach (range(1, 1) as $rRetry) {
        $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
        $rOptions = [
            'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $rContext = stream_context_create($rOptions);
        $rData = file_get_contents($rChannelData, false, $rContext);

        if (strpos($rData, '<MPD') !== false) {
            $rMPD = simplexml_load_string($rData);
            #$rGetHeaders = get_headers($rChannelData);
            #foreach($rGetHeaders as $id => $header)
            #{
            #	if(strpos($header, 'Location:') !== false)
            #	{
            #		$rGetLocation = $header;
            #	}
            #}
            #$baseurl = getURLBase(str_replace(array('Location: http://',':80'),array('http://',''), $rGetLocation));
            $baseurl = getURLBase(str_replace(array('Location: http://',':80'),array('http://',''), $rChannelData));
            $rBaseURL = $baseurl;
            $rVideoStart = NULL;
            $rAudioStart = NULL;
            $rVideoTemplate = NULL;
            $rPSSH = NULL;
            $rSegmentStart = 0;

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['contentType'] == 'video' AND $rAdaptationSet->attributes()['id'] == 1) {
                    $rID = $rAdaptationSet->Representation[count($rAdaptationSet->Representation) - 1]->attributes()['id'];
                    #$rID = 'video=2100000.track_id=10004';
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);

                    if(!$rPSSH)
                    {
                        file_put_contents(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment), file_get_contents($rInitSegment, false, $rContext));
                        $rPSSH_res = shell_exec($rMP4dump . ' --verbosity 3 --format json ' . MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment));
                        preg_match('#"data":"\\[(.+?)\\]#', $rPSSH_res, $rPSSH);
                        $rPSSH = getWVBox(hex_to_base64(str_replace(' ', '', $rPSSH[1])));
                        plog('PSSH: ' . $rPSSH);
                    }
                }
            }

            $rObject = [
                'pssh'     => $rPSSH,
                'audio'    => NULL,
                'video'    => NULL,
                'segments' => [],
                'add'      => 100
            ];

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['contentType'] == 'video' AND $rAdaptationSet->attributes()['id'] == 1) {
                    $rID = $rAdaptationSet->Representation[count($rAdaptationSet->Representation) - 1]->attributes()['id'];
                    #$rID = 'video=2100000.track_id=10004';
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    $rObject['video'] = $rInitSegment;
                    foreach ($rAdaptationSet->SegmentTemplate->SegmentTimeline->S as $rSegment) {
                        if (isset($rSegment->attributes()['t'])) {
                            $rVideoStart = $rSegment->attributes()['t'];
                            $rObject['add'] = $rSegment->attributes()['d'];
                        }
                        if (isset($rSegment->attributes()['r'])) {
                            $rRepeats = intval($rSegment->attributes()['r']) + 1;
                        }
                        else {
                            $rRepeats = 1;
                        }

                        foreach (range(1, $rRepeats) as $rRepeat) {

                            $rVideoStart += intval($rSegment->attributes()['d']);
                            $rObject['segments'][$rVideoStart]['video'] = str_replace('$Time$', $rVideoStart, $rBaseURL . $rVideoTemplate);
                        }
                    }
                }
            }

            unset($rVideoStart);
            $rSegmentsCount = count($rObject['segments']);
            plog('Segments: '.$rSegmentsCount);
            $rLangAvailable = array();
            plog('Trying to find audio track for language <strong>'.strtoupper($rLang).'</strong>...');
            //looking for audio tracks
            foreach($rMPD->Period->AdaptationSet as $rAdaptationSet){
                if(($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4') OR ($rAdaptationSet->attributes()['contentType'] == 'audio')){
                    array_push($rLangAvailable, $rAdaptationSet->attributes()['lang']);
                }
            }

            if(!in_array($rLang, $rLangAvailable)){
                plog('Couldn\'t find audio track');
                plog('Audio track set to default');
                if($rLangAvailable[0] != ''){
                    $rLang = $rLangAvailable[0];
                    plog('Audio stream set to <strong>'.strtoupper($rLang).'</strong>');
                }
                if(isset($rLangAvailable[1])){
                    plog('Second audio track is <strong>'.strtoupper($rLangAvailable[1]).'</strong>');
                }
            }else{
                plog('Audio track found!');
            }

            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if (($rAdaptationSet->attributes()['lang'] == $rLang) OR ($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4' AND !isset($rAdaptationSet->attributes()['lang'])) OR ($rAdaptationSet->attributes()['contentType'] == 'audio' AND !isset($rAdaptationSet->attributes()['lang']))) {
                    $rID = $rAdaptationSet->Representation[0]->attributes()['id'];
                    $rAudioTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    $rObject['audio'] = $rInitSegment;

                    foreach ($rAdaptationSet->SegmentTemplate->SegmentTimeline->S as $rSegment) {
                        if (isset($rSegment->attributes()['t'])) {
                            $rAudioStart = $rSegment->attributes()['t'];
                        }

                        if (isset($rSegment->attributes()['r'])) {
                            $rRepeats = intval($rSegment->attributes()['r']) + 1;
                        }
                        else {
                            $rRepeats = 1;
                        }

                        foreach (range(1, $rRepeats) as $rRepeat) {
                            $rAudioStart += intval($rSegment->attributes()['d']);
                            $rVideoStart = array_keys($rObject['segments']);
                            $rObject['segments'][$rVideoStart[$rSegmentStart]]['audio'] = str_replace('$Time$', $rAudioStart, $rBaseURL . $rAudioTemplate);
                            #plog('Index: '.$rSegmentStart);
                            #plog('Segment: '.$rVideoStart[$rSegmentStart]);
                            #plog('	Video segment: '.$rObject['segments'][$rVideoStart[$rSegmentStart]]['video']);
                            #plog('	Audio segment: '.$rObject['segments'][$rVideoStart[$rSegmentStart]]['audio']);
                            #plog('----');
                            $rSegmentStart++;
                        }

                    }

                    if (!$rLimit) {
                        $rLimit = $rMaxSegments;
                    }

                    foreach($rObject['segments'] as $rSegmentPair => $urls)
                    {
                        ksort($rObject['segments'][$rSegmentPair]);
                    }

                    $rObject['segments'] = array_slice($rObject['segments'], -1 * $rLimit - 10, $rLimit, true);
                    #$rObject['segments'] = array_slice($rObject['segments'], 50, 5, true);
                    return $rObject;

                }
            }
        }
    }
}
function getServiceSegmentsYeloPlay($rChannelData, $rLimit = NULL, $rServiceName, $rLang)
{
    global $rMaxSegments;
    global $rMP4dump;

    if (!file_exists(MAIN_DIR . Init . $rServiceName)) {
        mkdir(MAIN_DIR . Init . $rServiceName, 493, true);
    }

    foreach (range(1, 1) as $rRetry) {
        $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
        $rOptions = [
            'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $rContext = stream_context_create($rOptions);
        $rData = file_get_contents($rChannelData, false, $rContext);

        if (strpos($rData, '<MPD') !== false) {
            $rMPD = simplexml_load_string($rData);
            $pathBase = $rMPD->Period->BaseURL;
            if($pathBase)
            {
                preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $pathBase, $matches);
                if(!$matches)
                {
                    $baseurl = getURLBase($rChannelData).$pathBase;
                }
            }else{
                $baseurl = getURLBase($rChannelData);
            }
            $rBaseURL = $baseurl;
            $rVideoStart = NULL;
            $rAudioStart = NULL;
            $rVideoTemplate = NULL;
            $rIndex = [];
            $rPSSH = NULL;
            $rSegmentStart = 0;

            //gets pssh from first Period inside manifest
            foreach ($rMPD->Period->AdaptationSet as $rAdaptationSet) {
                if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' OR $rAdaptationSet->attributes()['contentType'] == 'video') {
                    $rID = $rAdaptationSet->Representation[count($rAdaptationSet->Representation) - 1]->attributes()['id'];
                    #$rID = 'video=2100000.track_id=10004';
                    $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                    $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                    foreach($rMPD->Period->AdaptationSet[0]->ContentProtection as $rContentProtection)
                    {
                        if($rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed' OR $rContentProtection->attributes()['schemeIdUri'] == 'urn:uuid:EDEF8BA9-79D6-4ACE-A3C8-27DCD51D21ED')
                        {
                            preg_match('/(?:\w\w\w\w)++\d\w\+[^<]++/', $rData, $matches);
                            if($matches)
                            {
                                $rPSSH  = $matches[0];
                                plog('PSSH: ' . $rPSSH);

                            }
                        }
                    }
                    if(!$rPSSH)
                    {
                        file_put_contents(MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment), file_get_contents($rInitSegment, false, $rContext));
                        $rPSSH_res = shell_exec($rMP4dump . ' --verbosity 3 --format json ' . MAIN_DIR . Init . $rServiceName . '/' . md5($rInitSegment));
                        preg_match('#"data":"\\[(.+?)\\]#', $rPSSH_res, $rPSSH);
                        $rPSSH = getWVBox(hex_to_base64(str_replace(' ', '', $rPSSH[1])));
                        plog('PSSH: ' . $rPSSH);
                    }
                    break;
                }
            }

            $rObject = [
                'pssh'     => $rPSSH,
                'audio'    => NULL,
                'video'    => NULL,
                'segments' => [],
                'add'      => 100
            ];

            //loops through all Periods inside manifest
            foreach($rMPD->Period as $id => $Period){
                #echo $Period->attributes()['id']."\n";
                //Loop through all video $Time segments and creates a column vector
                foreach ($Period->AdaptationSet as $rAdaptationSet) {
                    if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' OR $rAdaptationSet->attributes()['contentType'] == 'video') {
                        $rID = $rAdaptationSet->Representation[count($rAdaptationSet->Representation) - 1]->attributes()['id'];
                        #$rID = 'video=2100000.track_id=10004';
                        $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                        $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                        $rObject['video'] = $rInitSegment;
                        foreach ($rAdaptationSet->SegmentTemplate->SegmentTimeline->S as $rSegment) {
                            if (isset($rSegment->attributes()['t'])) {
                                $rVideoStart = $rSegment->attributes()['t'];
                                $rObject['add'] = $rSegment->attributes()['d'];
                            }
                            if (isset($rSegment->attributes()['r'])) {
                                $rRepeats = intval($rSegment->attributes()['r']) + 1;
                            }
                            else {
                                $rRepeats = 1;
                            }

                            foreach (range(1, $rRepeats) as $rRepeat) {

                                $rVideoStart += intval($rSegment->attributes()['d']);
                                array_push($rIndex, $rVideoStart);
                                #$rObject['segments'][$rVideoStart]['video'] = str_replace('$Time$', $rVideoStart, $rBaseURL . $rVideoTemplate);
                            }
                        }
                    }
                }
            }
            //loops through all Periods inside manifest
            //Populates audio segments in order based on column vector previously created
            foreach($rMPD->Period as $id => $Period){
                foreach ($Period->AdaptationSet as $rAdaptationSet) {
                    if (((($rAdaptationSet->attributes()['lang'] == $rLang OR $rAdaptationSet->attributes()['lang'] == 'en' OR $rAdaptationSet->attributes()['lang'] == 'fr') OR $rAdaptationSet->attributes()['lang'] == 'spa') AND $rAdaptationSet->attributes()['mimeType'] != 'text/vtt') OR ($rAdaptationSet->attributes()['mimeType'] == 'audio/mp4' AND !isset($rAdaptationSet->attributes()['lang'])) OR ($rAdaptationSet->attributes()['contentType'] == 'audio' AND !isset($rAdaptationSet->attributes()['lang']))) {
                        $rID = $rAdaptationSet->Representation[count($rAdaptationSet->Representation) - 1]->attributes()['id'];
                        #$rID = 'video=2100000.track_id=10004';
                        $rAudioTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                        $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                        $rObject['audio'] = $rInitSegment;
                        foreach ($rAdaptationSet->SegmentTemplate->SegmentTimeline->S as $rSegment) {
                            if (isset($rSegment->attributes()['t'])) {
                                $rAudioStart = $rSegment->attributes()['t'];
                                $rObject['add'] = $rSegment->attributes()['d'];
                            }
                            if (isset($rSegment->attributes()['r'])) {
                                $rRepeats = intval($rSegment->attributes()['r']) + 1;
                            }
                            else {
                                $rRepeats = 1;
                            }

                            foreach (range(1, $rRepeats) as $rRepeat) {
                                $rAudioIndex = $rIndex[$rSegmentStart];
                                $rAudioStart += intval($rSegment->attributes()['d']);
                                $rObject['segments'][$rAudioIndex]['audio'] = str_replace('$Time$', $rAudioStart, $rBaseURL . $rAudioTemplate);
                                #echo "Counter: ".$rSegmentStart."\n";
                                $rSegmentStart++;
                            }
                        }

                    }
                }
            }

            //loops through all Periods inside manifest
            //loop through all video segments and populates based on column vector previously created
            foreach($rMPD->Period as $id => $Period){
                #echo $Period->attributes()['id']."\n";
                foreach ($Period->AdaptationSet as $rAdaptationSet) {
                    if ($rAdaptationSet->attributes()['mimeType'] == 'video/mp4' OR $rAdaptationSet->attributes()['contentType'] == 'video') {
                        $rID = $rAdaptationSet->Representation[count($rAdaptationSet->Representation) - 1]->attributes()['id'];
                        #$rID = 'video=2100000.track_id=10004';
                        $rVideoTemplate = str_replace('$RepresentationID$', $rID, $rAdaptationSet->SegmentTemplate[0]->attributes()['media']);
                        $rInitSegment = str_replace('$RepresentationID$', $rID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']);
                        $rObject['video'] = $rInitSegment;
                        foreach ($rAdaptationSet->SegmentTemplate->SegmentTimeline->S as $rSegment) {
                            if (isset($rSegment->attributes()['t'])) {
                                $rVideoStart = $rSegment->attributes()['t'];
                                $rObject['add'] = $rSegment->attributes()['d'];
                            }
                            if (isset($rSegment->attributes()['r'])) {
                                $rRepeats = intval($rSegment->attributes()['r']) + 1;
                            }
                            else {
                                $rRepeats = 1;
                            }

                            foreach (range(1, $rRepeats) as $rRepeat) {

                                $rVideoStart += intval($rSegment->attributes()['d']);
                                #array_push($rIndex, $rVideoStart);
                                $rObject['segments'][$rVideoStart]['video'] = str_replace('$Time$', $rVideoStart, $rBaseURL . $rVideoTemplate);
                            }
                        }
                    }
                }
            }

            $rSegmentsCount = count($rObject['segments']);
            plog('Segments: '.$rSegmentsCount);
            #$rObject['segments'] = array_slice($rObject['segments'], -1*$rLimit, $rLimit, true);
            #$rObject['segments'] = array_slice($rObject['segments'], 15, $rLimit, true);
            $rObject['segments'] = array_slice($rObject['segments'], $rSegmentsCount -3*$rLimit, $rLimit, true);
            return $rObject;
        }
    }
}
function getSlingSegments($rMPD, $rLimit = 15)
{
    $rSegments = [
        'pssh' => NULL,
        'audio' => NULL,
        'video' => NULL,
        'segments' => [],
        'expires' => NULL
    ];
    if (!file_exists(MAIN_DIR . Init . 'sling')) {
        mkdir(MAIN_DIR . Init . 'sling', 493, true);
    }
    $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
    $rOptions = [
        'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    $rContext = stream_context_create($rOptions);
    $rData = file_get_contents($rMPD, false, $rContext);

    if (strpos($rData, '<MPD') !== false)
        $rMPD = simplexml_load_string($rData);

    $rStartTime = strtotime($rMPD->attributes()['availabilityStartTime']);

    if (!$rStartTime) {
        $rStartTime = strtotime(str_replace(':00Z', ':09Z', $rMPD->attributes()['publishTime']));
    }

    $rSegmentDuration = floatval(str_replace('S', '', str_replace('PT', '', $rMPD->attributes()['maxSegmentDuration'])));
    $rDelay = 0;
    $rElapsed = time() - $rStartTime - $rDelay;

    if ($rElapsed < 0)
        $rElapsed = 15;


    foreach ($rMPD->Period as $rPeriod) {
        $rPeriodStart = floatval(str_replace('S', '', str_replace('PT', '', $rPeriod->attributes()['start'])));

        if ($rPeriodStart <= $rElapsed) {
            $rPeriodElapsed = $rElapsed - $rPeriodStart;
            $rDuration = floatval(str_replace('S', '', str_replace('PT', '', $rPeriod->attributes()['duration'])));
            $rStartNumber = intval($rPeriod->AdaptationSet[0]->SegmentTemplate->attributes()['startNumber']);
            $rSegments['expires'] = $rStartTime + $rDuration;
            $rCurrentSegment = floor($rStartNumber + ($rPeriodElapsed / $rSegmentDuration));
            $rBaseURL = $rPeriod->BaseURL[0];
            $rPSSH = NULL;

            foreach ($rPeriod->AdaptationSet[0]->ContentProtection as $rContentProtection) {
                if ($rContentProtection->attributes()->schemeIdUri == 'urn:uuid:edef8ba9-79d6-4ace-a3c8-27dcd51d21ed') {
                    list($rPSSH) = explode('</cenc:pssh>', explode('<cenc:pssh>', $rContentProtection->asXML())[1]);

                    if ($rPSSH) {
                        $rSegments['pssh'] = $rPSSH;

                        $rAdaptationData = [];

                        foreach ($rPeriod->AdaptationSet as $rAdaptationSet) {
                            $rRepID = $rAdaptationSet->Representation[0]->attributes()['id'];
                            $rType = $rAdaptationSet->attributes()['contentType'];
                            $rAdaptationData[strval($rType)] = ['init' => str_replace('$RepresentationID$', $rRepID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['initialization']), 'template' => str_replace('$RepresentationID$', $rRepID, $rBaseURL . $rAdaptationSet->SegmentTemplate[0]->attributes()['media'])];

                            $rSegments[strval($rType)] = $rAdaptationData[strval($rType)]['init'];
                        }

                        foreach (range($rCurrentSegment - $rLimit, $rCurrentSegment) as $x) {
                            if (0 < $x) {
                                $rSegmentID = dechex(intval($x));
                                $rSegmentArray = [
                                    'number' => $x,
                                    'hex' => $rSegmentID,
                                    'pssh' => $rPSSH,
                                    'audio' => ['init' => NULL, 'segment' => NULL],
                                    'video' => ['init' => NULL, 'segment' => NULL]
                                ];

                                foreach (['audio', 'video'] as $rType) {
                                    //  echo 'indice vale '.$x.PHP_EOL;
                                    //   $rSegmentArray[$rType]['init'] = $rAdaptationData[$rType]['init'];
                                    //  $rSegmentArray[$rType]['segment'] = str_replace('$Number%08x$', $rSegmentID, $rAdaptationData[$rType]['template']);
                                    $rSegments['segments'][$x][$rType] = str_replace('$Number%08x$', $rSegmentID, $rAdaptationData[$rType]['template']);
                                }
                            }
                        }
                    }
                } else if ($rContentProtection->attributes()['schemeIdUri'] == 'urn:mpeg:dash:mp4protection:2011') {
                    $namespaces = $rContentProtection->getNamespaces(true);
                    if (array_key_exists('cenc', $namespaces) and isset($rContentProtection->attributes($namespaces['cenc'])['default_KID'])) {
                        $kid = $rContentProtection->attributes($namespaces['cenc'])['default_KID']->__toString();
                        $rSegments['videoKID'] = str_replace('-', '', $kid);
                        $rSegments['audioKID'] = str_replace('-', '', $kid);
                    }
                }
            }
        }
    }
    $rSegments['segments'] = array_slice($rSegments['segments'], -1 * $rLimit, $rLimit, true);
    return $rSegments;
}

function getISMSegments($ism, $service, $channelName, $rLanguage, $rLimit = 15)
{
    $rSegments = [
        'audio' => NULL,
        'video' => NULL,
        'segments' => [],
    ];
    $initDir = MAIN_DIR . ISM_INITS . $service . '/' . $channelName . '/';

    if (!file_exists($initDir)) {
        mkdir($initDir, 493, true);
    }
    $baseurl = getURLBase($ism);

    $rUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36';
    $rOptions = [
        'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    $rContext = stream_context_create($rOptions);
    $rData = file_get_contents($ism, false, $rContext);

    if (strpos($rData, '<SmoothStreamingMedia'))
        $xml = simplexml_load_string($rData);

    $timeScale = intval($xml->attributes()['TimeScale']);

//looking for audio tracks
    $rLangAvailable = array();
    foreach ($xml->StreamIndex as $index) {
        if (($index->attributes()['Type'] == 'audio') or ($index->attributes()['Type'] == 'audio/mp4'))
            array_push($rLangAvailable, $index->attributes()['Language']);

    }

    if (!in_array($rLanguage, $rLangAvailable)) {
        plog('Couldn\'t find audio track');
        $rLang = $rLangAvailable[0];
        plog('Audio stream set to <strong>' . strtoupper($rLang) . '</strong>');
        if (isset($rLangAvailable[1])) {
            plog('Second audio track is <strong>' . strtoupper($rLangAvailable[1]) . '</strong>');
        }
    } else {
        $rLang = $rLanguage;
        plog('Audio track found!');
    }
    $videoStreamIndex = null;
    $audioStreamIndex = null;


    foreach ($xml->StreamIndex as $index) {

        if (0 === strpos($index->attributes()['Type'], 'video'))
            $videoStreamIndex = $index;
        else if (0 === strpos($index->attributes()['Type'], 'audio') and 0 === strcmp($rLang, strval($index->attributes()['Language'])))
            $audioStreamIndex = $index;
    }

// VIDEO PROCESSING
    $numQualities = intval($videoStreamIndex->attributes()['QualityLevels']);
    $qualityLevel = $videoStreamIndex->QualityLevel[$numQualities - 1];
    $videoBirate = $qualityLevel->attributes()['Bitrate'];
    $videoTemplate = $videoStreamIndex->attributes()['Url'];
    $videoStart = 0;
    foreach ($videoStreamIndex->c as $segment) {
        $attributes = $segment->attributes();
        if (isset($attributes['t'])) {
            $videoStart = intval($attributes['t']);
        }


        $rSegments['segments'][$videoStart]['video'] = $baseurl . str_replace(['{bitrate}', '{start time}'], [$videoBirate, $videoStart], $videoTemplate);
        $videoStart = $videoStart + intval($attributes['d']);
    }
    $rSegments['segments'] = array_slice($rSegments['segments'], -1 * $rLimit, $rLimit, true);
// Audio processing
    $audioSegments = array();
    $numQualities = intval($audioStreamIndex->attributes()['QualityLevels']);
    $qualityLevel = $audioStreamIndex->QualityLevel[$numQualities - 1];
    $audioBirate = $qualityLevel->attributes()['Bitrate'];
    $audioTemplate = $audioStreamIndex->attributes()['Url'];
    $audioStart = 0;
    foreach ($audioStreamIndex->c as $segment) {
        $attributes = $segment->attributes();
        if (isset($attributes['t'])) {
            $audioStart = intval($attributes['t']);
        }
        array_push($audioSegments, $baseurl . str_replace(['{bitrate}', '{start time}'], [$audioBirate, $audioStart], $audioTemplate));
        $audioStart = $audioStart + intval($attributes['d']);
    }
    $audioSegments = array_slice($audioSegments, -1 * $rLimit, $rLimit, true);
    foreach ($rSegments['segments'] as $id => $info) {
        $rSegments['segments'][$id]['audio'] = array_shift($audioSegments);
    }
    return $rSegments;
}

function getProcessCount()
{
    exec('pgrep -u wvtohls | wc -l 2>&1', $rOutput, $rRet);
    return intval($rOutput[0]);
}

function kidInKey($kid, $keyCached)
{
    $kidInKey = explode(':', $keyCached);
    $kidInKey = trim($kidInKey[0]);
    return (0 === strcmp($kidInKey, trim($kid)));
}

function setKeyCache($serviceName, $channelName, $rValue, $kids = NULL)
{

    $rKey = md5($serviceName . $channelName);
    $keyCached = [
        'key' => $rValue
    ];
    if (isset($kids))
        $keyCached['kids'] = $kids;

    $keyCached = json_encode($keyCached);
    file_put_contents(MAIN_DIR . 'cache/keystore/' . $rKey . '.key', encryptkey($keyCached));
    return file_exists(MAIN_DIR . 'cache/keystore/' . $rKey . '.key');
}

function getKeyCache($serviceName, $channelName, $kids = NULL)
{
    $res = NULL;
    $areKidsKnown = True;
    $rKey = md5($serviceName . $channelName);
    if (file_exists(MAIN_DIR . 'cache/keystore/' . $rKey . '.key')) {

        $key = decryptkey(file_get_contents(MAIN_DIR . 'cache/keystore/' . $rKey . '.key'));

        if (0 == strcmp('[', $key[0])) {
            $res = json_decode($key, 1);
            if (array_key_exists('kids', $res) and isset($kids)) {
                if (!is_array($res['kids']) and !is_array($kids))
                    $areKidsKnown = (0 === strcmp($res['kids'], $kids));
                elseif (is_array($res['kids']) && is_array($kids)) {
                    foreach ($res['kids'] as $kid) {
                        $areKidsKnown = $areKidsKnown && in_array($kid, $kids);
                    }
                }
            }
        } else
            $res = json_decode($key, 1)['key'];
    }
    return $res;
}

function getKey($rType, $rChannel, $kid = NULL, $pssh = NULL)
{
    $res = array();
    $res['status'] = 'FAIL';
    $res['key'] = array();
    if (file_exists('/home/wvtohls/origin/' . $rType . '.json')) {
        $json = file_get_contents('/home/wvtohls/origin/' . $rType . '.json');

        $json = json_decode($json, 1);

        if (array_key_exists('key', $json[$rChannel]) and is_array($json[$rChannel]['key']) and is_array($kid)) {
            $num = count($json[$rChannel]['key']);

            foreach ($kid as $kidsearch) {
                $found = False;
                $i = 0;

                do {
                    $kidStored = explode(':', $json[$rChannel]['key'][$i])[0];
                    if (0 == strcmp($kidsearch, $kidStored) and !in_array($kidsearch, $res['key'])) {
                        array_push($res['key'], $json[$rChannel]['key'][$i]);
                        $found = True;
                    }
                    $i++;
                } while (!$found and $i < $num);
            }
            $res['status'] = 'OK';
        } else if (array_key_exists('key', $json[$rChannel]) and !is_array($json[$rChannel]['key'])) {
            if (isset($kid)) {

                $kidStored = strtolower(explode(':', $json[$rChannel]['key'])[0]);
                if (0 == strcmp(strtolower($kid), $kidStored)) {
                    $res['key'] = $json[$rChannel]['key'];
                    $res['status'] = 'OK';
                }
            } else {
                $res['status'] = 'OK';
                $res['key'] = $json[$rChannel]['key'];
            }
        } elseif (!array_key_exists('key', $json[$rChannel])) {
            // TODO OBTENER LLAVE NUEVA PARA EL CANAL

            do {
                $resul = exec('python3 /home/wvtohls/widevine/get_keys.py --pssh ' . $pssh . ' --license_url http://localhost:57127/slingkey.php');
                #print_r('WIDEVINE REQUEST RESULT ' . $resul . PHP_EOL);
                preg_match("@'(.+)'@", $resul, $matches);
                if (count($matches) > 1) {
                    $res['status'] = 'OK';
                    $res['key'] = $matches[1];
                } else {
                    plog('WAITING 3 SECONDS TO REQUEST THE KEY AGAIN');
                    sleep(3);
                }
            } while (count($matches) < 2);
        }
    }
    return $res;
}

function openCache($rChannel)
{
    if (file_exists(MAIN_DIR . 'cache/' . $rChannel . '.db')) {
        return json_decode(file_get_contents(MAIN_DIR . 'cache/' . $rChannel . '.db'), true);
    }

    return [];
}

function deleteCache($rChannel)
{
    if (file_exists(MAIN_DIR . 'cache/' . $rChannel . '.db'))
        unlink(MAIN_DIR . 'cache/' . $rChannel . '.db');
    return [];
}

function receiveMessage($fileDescriptor, $waitTimeoutUsecs = null)
{
    $w = null;
    $r = array($fileDescriptor);
    $e = null;
    if ($waitTimeoutUsecs)
        $status = stream_select($r, $w, $e, 0, $waitTimeoutUsecs);
    else
        $status = stream_select($r, $w, $e, null);
    if ($status)
        $line = fgets($fileDescriptor);
    else
        $line = null;
    return $line;
}

function startSegmentsServer(&$ipcMessenger, $segmentsLocation)
{
    $res = array();
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w']
    ];

    $command = 'php /home/wvtohls/includes/videoServer.php';

    $process = proc_open($command, $descriptors, $pipes);

    if (is_resource($process)) {
        stream_set_blocking($pipes[0], 1);
        stream_set_blocking($pipes[1], 1);
        $additionalInfo = array(
            'source' => $segmentsLocation
        );

        $send = $ipcMessenger->generateMessage(ipcMessage::START, 'START SERVER', $additionalInfo);

        fwrite($pipes[0], $send);
        $message = receiveMessage($pipes[1], 7777777);
        $ok = $ipcMessenger->acceptMessage($message);
        if ($message and $ok) {
            switch ($ipcMessenger->getType()) {
                case ipcMessage::ACK:

                    $additionalInfo = $ipcMessenger->getAdditionalInfo();
                    if (array_key_exists('socket_address', $additionalInfo)) {
                        $res['pipes'] = $pipes;
                        $res['socket_address'] = $additionalInfo['socket_address'];
                        $res['process'] = $process;
                        $res['pid'] = $additionalInfo['pid'];
                    }
            }
        }
    }
    return $res;
}

function clearCache($rDatabase, $rID)
{
    unset($rDatabase[$rID]);
    return $rDatabase;
}

function getCache($rDatabase, $rID)
{
    if (isset($rDatabase[$rID])) {
        return $rDatabase[$rID]['value'];
    }

    return NULL;
}

function getCacheDisk($rChannel, $id)
{
    $ret = null;
    $filename = MAIN_DIR . 'cache/' . $rChannel . '.db';
    if (file_exists($filename)) {
        $ret = file_get_contents($filename);
        $ret = json_decode($ret, 1)[$id]['value'];
    }
    return $ret;
}

function setCache($rDatabase, $rID, $rValue)
{
    global $rCacheTime;
    $rDatabase[$rID] = ['value' => $rValue, 'expires' => time() + $rCacheTime];
    return $rDatabase;
}

function saveCache($rChannel, $rDatabase)
{
    file_put_contents(MAIN_DIR . 'cache/' . $rChannel . '.db', json_encode($rDatabase));
}

function getPersistence()
{
    if (file_exists(MAIN_DIR . 'config/persistence.db')) {
        $rPersistence = json_decode(file_get_contents(MAIN_DIR . 'config/persistence.db'), true);
    } else {
        $rPersistence = [];
    }

    return $rPersistence;
}

function addPersistence($rScript, $rChannel)
{
    $rPersistence = getpersistence();

    if (!in_array($rChannel, $rPersistence[$rScript])) {
        $rPersistence[$rScript][] = $rChannel;
    }

    file_put_contents(MAIN_DIR . 'config/persistence.db', json_encode($rPersistence));
}

function removePersistence($rScript, $rChannel)
{
    $rPersistence = getpersistence();

    if (($rKey = array_search($rChannel, $rPersistence[$rScript])) !== false) {
        unset($rPersistence[$rScript][$rKey]);
    }

    file_put_contents(MAIN_DIR . 'config/persistence.db', json_encode($rPersistence));
}

function getKeyCrypt($rType, $rChannel, $kid = NULL)
{
    $res = array();
    $res['status'] = 'FAIL';
    $res['key'] = array();
    if (file_exists('/home/wvtohls/origin/' . $rType . '.json')) {
        $json = file_get_contents('/home/wvtohls/origin/' . $rType . '.json');
        $json = decryptKey($json);
        $json = json_decode($json, 1);

        if (array_key_exists('key', $json[$rChannel]) and is_array($json[$rChannel]['key']) and is_array($kid)) {
            $num = count($json[$rChannel]['key']);

            foreach ($kid as $kidsearch) {
                $found = False;
                $i = 0;

                do {
                    $kidStored = explode(':', $json[$rChannel]['key'][$i])[0];
                    if (0 == strcmp($kidsearch, $kidStored) and !in_array($kidsearch, $res['key'])) {
                        array_push($res['key'], $json[$rChannel]['key'][$i]);
                        $found = True;
                    }
                    $i++;
                } while (!$found and $i < $num);
            }
            $res['status'] = 'OK';
        } else if (array_key_exists('key', $json[$rChannel]) and !is_array($json[$rChannel]['key'])) {
            if (isset($kid)) {

                $kidStored = strtolower(explode(':', $json[$rChannel]['key'])[0]);
                if (0 == strcmp(strtolower($kid), $kidStored)) {
                    $res['key'] = $json[$rChannel]['key'];
                    $res['status'] = 'OK';
                }
            } else {
                $res['status'] = 'OK';
                $res['key'] = $json[$rChannel]['key'];
            }
        }

    }
    return $res;
}

function combineSegment($rVideo, $rAudio, $rOutput, $audioDelay = 0,$encodeAudio=False)
{
    global $rFFMpeg;
    $audioDelay=intval($audioDelay);

    if ($audioDelay==0 and !$encodeAudio) {
        print_r('SOLAMENTE UNIENDO TAL CUAL ---------------------------');
        $rWait = exec($rFFMpeg . ' -hide_banner -loglevel panic -y -nostdin -i "' . $rVideo . '" -i "' . $rAudio . '" -ignore_editlist 1 -c:v copy -c:a copy "' . $rOutput . '" ');
    }
    else if ($audioDelay!=0 and !$encodeAudio) {
        print_r('SOLAMENTE RETRASANDO EL AUDIO '.$audioDelay.' ---------------------------');
        if($audioDelay>0){
#            $rWait = exec('/usr/bin/ffmpeg  -hide_banner -loglevel panic -y -nostdin -i "' . $rVideo . '" -i "' . $rAudio . '" -c:v copy -c:a aac -map 0:v -map 1:a -af "adelay='.$audioDelay.'|'.$audioDelay.'" "' . $rOutput . '" ');
$comando='/usr/bin/ffmpeg -hide_banner -loglevel panic -y -nostdin -itsoffset ' . $audioDelay .' -i "'.$rAudio . '" -i "' . $rVideo . '" -c:v copy -c:a aac  -map 0:a -map 1:v "' . $rOutput . '" ';

            $rWait = exec($comando);
        }
        else if($audioDelay<0)
        {
              $audioDelay= $audioDelay .'ms';

          //  $comando='/usr/bin/ffmpeg -hide_banner -loglevel panic -y -nostdin -itsoffset ' . $audioDelay .' -i "'.$rVideo . '" -i "' . $rAudio . '" -c:v copy -c:a aac -map 0:v -map 1:a "' . $rOutput . '" ';
            $comando='/usr/bin/ffmpeg -hide_banner -loglevel panic -y -nostdin -itsoffset ' . $audioDelay .' -i "'.$rAudio . '" -i "' . $rVideo . '" -c:v copy -c:a aac  -map 0:a -map 1:v "' . $rOutput . '" ';
            print_r('retrasando con el comando '.$comando);
            $rWait = exec($comando);
        }
        //$rWait = exec($rFFMpeg . ' -hide_banner -loglevel panic -y -nostdin -i "' . $rVideo . '" -itsoffset ' . $audioDelay . ' -i "' . $rAudio . '" -c:v copy -c:a copy -map 0:v -map 1:a "' . $rOutput . '" ');

       // $rWait = exec('/usr/bin/ffmpeg  -hide_banner -loglevel panic -y -nostdin -i "' . $rVideo . '" -i "' . $rAudio . '" -c:v copy -c:a aac -map 0:v -map 1:a -af "adelay='.$audioDelay.'|'.$audioDelay.'" "' . $rOutput . '" ');


    }
    else if ($audioDelay==0 and $encodeAudio) {
        print_r('SOLAMENTE RECODIFICANDO EL AUDIO ---------------------------');
        $rWait = exec($rFFMpeg . ' -hide_banner -loglevel panic -y -nostdin -i "' . $rVideo .'" -i "' . $rAudio . '" -c:v copy -c:a aac -map 0:v -map 1:a "' . $rOutput . '" ');
    }
    else if ($audioDelay!=0 and $encodeAudio){
      //  $rWait = exec($rFFMpeg . ' -hide_banner -loglevel panic -y -nostdin -i "' . $rVideo . '" -itsoffset ' . $audioDelay . ' -i "' . $rAudio . '" -c:v libx264 -c:a aac -preset ultrafast -map 0:v -map 1:a "' . $rOutput . '" ');
        $temp1='/tmp/'.base64_encode($rVideo).'.ts';
        $temp2='/tmp/'.base64_encode($rAudio).'.ts';

     //   $command1= '/usr/bin/ffmpeg -hide_banner -loglevel panic -y -nostdin -i "' . $rVideo . '" -i "' . $rAudio . '" -c:v copy -c:a aac -preset ultrafast -map 0:v -map 1:a "' . $temp . '" ';


        $rWait = exec('/usr/bin/ffmpeg  -hide_banner -loglevel panic -y -nostdin -i "' . $rVideo . '" -i "' . $rAudio . '" -c:v copy -c:a aac -map 0:v -map 1:a -af "adelay='.$audioDelay.'|'.$audioDelay.'" "' . $rOutput . '" ');
        unlink($temp1);unlink($temp2);
echo 'Recodificando el audio con retraso -------------------------------'.PHP_EOL;
    }
    return file_exists($rOutput);
}

function clearMD5Cache($rChannel, $rLimit = 60)
{
    global $rVideoDir;
    $rFiles = glob($rVideoDir . '/' . $rChannel . '/cache/*.md5');
    usort($rFiles, function ($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    $rKeep = array_slice($rFiles, -1 * $rLimit, $rLimit, true);

    foreach ($rFiles as $rFile) {
        if (!in_array($rFile, $rKeep)) {
            unlink($rFile);
        }
    }
}


function downloadFilesWriteJson($rList, $rOutput, $rUA = NULL)
{
    global $rAria;
    $rTimeout = count($rList);

    if ($rTimeout < 3) {
        $rTimeout = 12;
    }

    if (0 < count($rList)) {
        $rURLs = join("\n", $rList);
        $rTempList = MAIN_DIR . 'tmp/' . md5($rURLs) . '.txt';
        file_put_contents($rTempList, $rURLs);

        if ($rUA) {
            exec($rAria . ' -U "' . $rUA . '" --connect-timeout=3 --timeout=' . $rTimeout . ' -i "' . $rTempList . '" --dir "' . $rOutput . '" 2>&1', $rOut, $rRet);
        } else {
            exec($rAria . ' --connect-timeout=3 --timeout=' . $rTimeout . ' -i "' . $rTempList . '" --dir "' . $rOutput . '" 2>&1', $rOut, $rRet);
        }

        unlink($rTempList);
    }

    return true;
}

function startPlaylistMovistar($rChannel)
{
    global $rFFMpeg;
    $rPlaylist = MAIN_DIR . 'video/' . $rChannel . '/playlist.txt';

    if (file_exists($rPlaylist)) {
        $rOutput = MAIN_DIR . 'hls/' . $rChannel . '/hls/playlist.m3u8';
        $rFormat = MAIN_DIR . 'hls/' . $rChannel . '/hls/segment%d.ts';
        if (!file_exists($rOutput)) {
            $rTime = time();
            $log = MAIN_DIR . 'logs/ffmpeg/' . $rChannel . '.log';
            $old_log = MAIN_DIR . 'logs/ffmpeg/' . $rChannel . '_' . $rTime . '.log';
            if (file_exists($old_log)) {
                #exec('mv '. $log .' '. $old_log);
                exec('rm ' . $log . ' ' . $old_log);
            }
            $rPID = exec($rFFMpeg . ' -y -nostdin -hide_banner -err_detect ignore_err -nofix_dts -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0 -re -probesize 15000000 -analyzeduration 15000000 -safe 0 -f concat -fflags +genpts -async 1 -i ' . $rPlaylist . ' -strict -2 -dn -acodec copy -vcodec copy -hls_flags delete_segments -hls_time 4 -hls_list_size 10 ' . $rOutput . ' > ' . $log . ' 2>&1 & echo $!;', $rScriptOut);
            return $rPID;
        }
    }
}


function startPlaylistFileProtocol($rChannel, $numSegments = 10, $segmentTime = 6)
{
    global $rFFMpeg;
    $rPlaylist = MAIN_DIR . 'video/' . $rChannel . '/playlist.txt';
    if (file_exists($rPlaylist)) {
        $rOutput = MAIN_DIR . 'hls/' . $rChannel . '/hls/playlist.m3u8';
        $rFormat = MAIN_DIR . 'hls/' . $rChannel . '/hls/segment%d.ts';


        $rTime = time();
        $log = MAIN_DIR . 'logs/ffmpeg/' . $rChannel . '.log';
        $old_log = MAIN_DIR . 'logs/ffmpeg/' . $rChannel . '_' . $rTime . '.log';
        if (file_exists($old_log)) {
            #exec('mv '. $log .' '. $old_log);
            exec('rm ' . $log . ' ' . $old_log);
        }
        # $rPID = exec($rFFMpeg . ' -y -nostdin -hide_banner -err_detect ignore_err -nofix_dts -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0 -probesize 15000000 -analyzeduration 15000000 -safe 0 -f concat -i ' . $rPlaylist . ' -strict -2 -dn -acodec copy -vcodec copy -hls_flags delete_segments -hls_time 2 -hls_list_size 10 '.$rOutput.' > ' . $log . ' 2>&1 & echo $!;', $rScriptOut);
        $rPID = exec($rFFMpeg . ' -y -nostdin -hide_banner -err_detect ignore_err -nofix_dts -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0 -re -probesize 15000000 -analyzeduration 15000000 -f concat -safe 0 -i \'' . $rPlaylist . '\' -vcodec copy -scodec copy -acodec copy -individual_header_trailer 0 -metadata service_provider="cimbor" -f segment -segment_format mpegts -segment_time ' . $segmentTime . ' -segment_list_size ' . $numSegments . ' -segment_format_options mpegts_flags=+initial_discontinuity:mpegts_copyts=1 -segment_list_type m3u8 -segment_list_flags +live+delete -segment_list \'' . $rOutput . '\' \'' . $rFormat . '\' > ' . $log . ' 2>&1 & echo $!;', $rScriptOut);

        return $rPID;
    }
}

function startPlaylistFifo($rChannel, $numSegments = 10, $segmentTime = 6)
{
    global $rFFMpeg;
    $fileDescriptor = null;
    $fifo = MAIN_DIR . 'video/' . $rChannel . '/fifo';
    if (file_exists($fifo))
        unlink($fifo);
    $mode = 0600;
    $created = posix_mkfifo($fifo, $mode);


    $rTime = time();
    $log = MAIN_DIR . 'logs/ffmpeg/' . $rChannel . '.log';
    $old_log = MAIN_DIR . 'logs/ffmpeg/' . $rChannel . '_' . $rTime . '.log';
    if (file_exists($old_log)) {
        #exec('mv '. $log .' '. $old_log);
        exec('rm ' . $log . ' ' . $old_log);
    }
    #$rPID = exec($rFFMpeg . ' -y -nostdin -hide_banner -err_detect ignore_err -nofix_dts -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0 -re -probesize 15000000 -analyzeduration 15000000 -f concat -safe 0 -i \'' . $rPlaylist . '\' -vcodec copy -scodec copy -acodec copy -individual_header_trailer 0 -metadata service_provider="cimbor" -f segment -segment_format mpegts -segment_time ' . $segmentTime . ' -segment_list_size ' . $numSegments . ' -segment_format_options mpegts_flags=+initial_discontinuity:mpegts_copyts=1 -segment_list_type m3u8 -segment_list_flags +live+delete -segment_list \'' . $rOutput . '\' \'' . $rFormat . '\' > ' . $log . ' 2>&1 & echo $!;', $rScriptOut);
    $rPID = exec($rFFMpeg . ' -y -nostdin -hide_banner -err_detect ignore_err -nofix_dts -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0 -re -probesize 15000000 -analyzeduration 15000000 -f concat -safe 0 -i \'' . $rPlaylist . '\' -vcodec copy -scodec copy -acodec copy -individual_header_trailer 0 -metadata service_provider="cimbor" -f segment -segment_format mpegts -segment_time ' . $segmentTime . ' -segment_list_size ' . $numSegments . ' -segment_format_options mpegts_flags=+initial_discontinuity:mpegts_copyts=1 -segment_list_type m3u8 -segment_list_flags +live+delete -segment_list \'' . $rOutput . '\' \'' . $rFormat . '\' > ' . $log . ' 2>&1 & echo $!;', $rScriptOut);
    return $rPID;

}

function startPlaylist($rChannel, $numSegments = 10, $segmentTime = 6)
{
    global $rFFMpeg;
    $rPlaylist = MAIN_DIR . 'video/' . $rChannel . '/playlist.txt';
    if (file_exists($rPlaylist)) {
        $rOutput = MAIN_DIR . 'hls/' . $rChannel . '/hls/playlist.m3u8';
        $rFormat = MAIN_DIR . 'hls/' . $rChannel . '/hls/segment%d.ts';


        $rTime = time();
        $log = MAIN_DIR . 'logs/ffmpeg/' . $rChannel . '.log';
        $old_log = MAIN_DIR . 'logs/ffmpeg/' . $rChannel . '_' . $rTime . '.log';
        if (file_exists($old_log)) {
            #exec('mv '. $log .' '. $old_log);
            exec('rm ' . $log . ' ' . $old_log);
        }
        #$rPID = exec($rFFMpeg . ' -y -nostdin -hide_banner -err_detect ignore_err -nofix_dts -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0 -re -probesize 15000000 -analyzeduration 15000000 -f concat -safe 0 -i \'' . $rPlaylist . '\' -vcodec copy -scodec copy -acodec copy -individual_header_trailer 0 -metadata service_provider="cimbor" -f segment -segment_format mpegts -segment_time ' . $segmentTime . ' -segment_list_size ' . $numSegments . ' -segment_format_options mpegts_flags=+initial_discontinuity:mpegts_copyts=1 -segment_list_type m3u8 -segment_list_flags +live+delete -segment_list \'' . $rOutput . '\' \'' . $rFormat . '\' > ' . $log . ' 2>&1 & echo $!;', $rScriptOut);
        $rPID = exec($rFFMpeg . ' -y -nostdin -hide_banner -err_detect ignore_err -nofix_dts -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0 -re -probesize 15000000 -analyzeduration 15000000 -f concat -safe 0 -i \'' . $rPlaylist . '\' -vcodec copy -scodec copy -acodec copy -individual_header_trailer 0 -metadata service_provider="cimbor" -f segment -segment_format mpegts -segment_time ' . $segmentTime . ' -segment_list_size ' . $numSegments . ' -segment_format_options mpegts_flags=+initial_discontinuity:mpegts_copyts=1 -segment_list_type m3u8 -segment_list_flags +live+delete -segment_list \'' . $rOutput . '\' \'' . $rFormat . '\' > ' . $log . ' 2>&1 & echo $!;', $rScriptOut);
        return $rPID;
    }
}

function startPlaylistUrl($rChannel, $url)
{
    global $rFFMpeg;
    $rPID = NULL;
    $rOutput = MAIN_DIR . 'hls/' . $rChannel . '/hls/playlist.m3u8';
    $rFormat = MAIN_DIR . 'hls/' . $rChannel . '/hls/segment%d.ts';
    if (!file_exists($rOutput)) {
        $rTime = time();
        $log = MAIN_DIR . 'logs/ffmpeg/' . $rChannel . '.log';
        $old_log = MAIN_DIR . 'logs/ffmpeg/' . $rChannel . '_' . $rTime . '.log';
        if (file_exists($old_log)) {
            #exec('mv '. $log .' '. $old_log);
            exec('rm ' . $log . ' ' . $old_log);
        }
        $comando = 'ffmpeg -re -reconnect 1 -reconnect_at_eof 1 -reconnect_streamed 1 -reconnect_delay_max 2 -y -nostdin -hide_banner -user_agent \'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.63 Safari/537.36\' -i ' . $url . ' -strict -2 -dn -c copy -hls_flags delete_segments -hls_time 6 -hls_list_size 10 -segment_format mpegts ' . $rOutput . ' > ' . $log . ' 2>&1 & echo $!;';
        $rPID = exec($comando);
#        plog('Comando FFMPEG '.$comando);
    }
    return $rPID;
}

function getStreamInfo($rID)
{
    global $rFFProbe;
    $tsFound = False;
    do {
        $segmentsFiles = glob(MAIN_DIR . 'hls/' . $rID . '/hls/*.ts');
        if (count($segmentsFiles))
            $tsFound = True;
        else {
            usleep(1000);

        }
    } while (!$tsFound);
    $rPlaylist = $segmentsFiles[0];
    $rOutput = null;
    if (file_exists($rPlaylist)) {
        exec($rFFProbe . ' -v quiet -print_format json -show_streams -show_format "' . $rPlaylist . '" 2>&1', $rOutput, $rRet);
    }

    return json_encode(json_decode(join(PHP_EOL, $rOutput), true));
}

define('MAIN_DIR', '/home/wvtohls/');
define('Init', 'logs/');
define('ISM_INITS', 'ism_inits/');
require MAIN_DIR . 'config/config.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(5);
$rMaxSegments = 32;
$rCacheTime = 21604;
$rVideoDir = MAIN_DIR . 'video';
$rHLSDir = MAIN_DIR . 'hls';
$rMP4Decrypt = MAIN_DIR . 'bin/mp4decrypt';
$rFFMpeg = MAIN_DIR . 'bin/ffmpeg ';
$rFFProbe = MAIN_DIR . 'bin/ffprobe';
$rMP4dump = MAIN_DIR . 'bin/mp4dump';
$rAria = '/usr/bin/aria2c';
$path = MAIN_DIR . 'cache/keystore/';
$rAESKey = '7c83a37df31ee733b01761187f5adad66a8ab475a425695eaa99bb1cfed2ed91';
$days = 1;

if ($handle = opendir($path)) {
    while (false !== $file = readdir($handle)) {
        if (is_file($path . $file)) {
            if (filemtime($path . $file) < (time() - ($days * 24 * 60 * 60))) {
                unlink($path . $file);
            }
        }
    }
}


?>
