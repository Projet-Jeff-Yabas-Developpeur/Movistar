<?php
require_once 'functions.php';
include 'segmentsMapper.php';
require '/home/wvtohls/includes/FfmpegHlsConversor.php';

set_time_limit(0);
ini_set('default_socket_timeout', 15);
ini_set('memory_limit', -1);

if (isset($argv[1]) && (strtoupper($argv[1]) == 'START') && isset($argv[2])) {
    pcntl_async_signals(true);

    function sig_handler($signo)
    {
        global $ffmpegConversor;
        global $rChannel;
      #  global $videoServerPipes;
      #  global $VideoServerprocess;

        switch ($signo) {
            case SIGTERM:
                // PARADA FFMPEG
                plog('Intentaré cerrar el proceso ffmpeg con PID ' . $ffmpegConversor->getFfmpegPID());
                $stopped = $ffmpegConversor->stopConversion();
                plog('Estado de parada de ffmpeg ' . $stopped);

/*                $rPID = getCacheDisk($rChannel,'videoserver_pid');
                plog('Intentaré cerrar el servidor de video con PID ' . $rPID);
                if (posix_kill($rPID, 0)) {

                    foreach ($videoServerPipes as $pipe) {
                        // Finds whether a variable is a resource
                        if (!is_null(@get_resource_type($pipe))) {
                            fclose($pipe);
                        }
                    }
                    posix_kill($rPID, SIGTERM);
                    if (!is_null(@get_resource_type($VideoServerprocess))) {
                        proc_close($VideoServerprocess);
                    }
                    while(posix_kill($rPID, 0)){
                        usleep(300000);
                        plog('Esperando a que termine el servidor de video en pid '.$rPID);
                    }
                    plog('Cerrado el servidor de video, tenia el pid ' . $rPID);
                }*/
                deleteCache($rChannel);
                exit();
        }
    }
// congiguración de las señales
    pcntl_signal(SIGTERM, "sig_handler");
    gc_enable();
    $rWait = 6000;
    $rFailWait = 15;
    $rKeyWait = 5;
    $rScript = 'molotov';
    $rLanguage = 'fr';
    $audioDelay=0;
    $numSegments = 18;
    $maxvres=1080;
    if (isset($argv[2])) {
        $rChannel = $argv[2];
        plog('Shutting down previous instances.');
        exec('kill -9 `ps -ef | grep \'DRM_' . $rChannel . '\' | grep -v grep | awk \'{print $2}\'`');
        cli_set_process_title('DRM_' . $rChannel);
        $rDatabase = deleteCache($rChannel);
        $rDatabase = setCache($rDatabase, 'time_start', time());
        saveCache($rChannel, $rDatabase);
        while (true) {
            plog('Starting: ' . $rChannel);
            plog('removing directory if exists.');
            exec('rm -rf ' . MAIN_DIR . 'video/' . $rChannel);
            exec('rm -rf ' . MAIN_DIR . 'hls/' . $rChannel);
            $rDatabase = setCache($rDatabase, 'php_pid', getmypid());
            saveCache($rChannel, $rDatabase);
            plog('Creando directorios para el canal ' . $rChannel);
            mkdir(MAIN_DIR . 'video/' . $rChannel);
            mkdir(MAIN_DIR . 'video/' . $rChannel . '/aria');
            mkdir(MAIN_DIR . 'video/' . $rChannel . '/decrypted');
            mkdir(MAIN_DIR . 'video/' . $rChannel . '/encrypted');
            mkdir(MAIN_DIR . 'video/' . $rChannel . '/final');
            mkdir(MAIN_DIR . 'hls/' . $rChannel);
            mkdir(MAIN_DIR . 'hls/' . $rChannel . '/hls');

            plog('Obteniendo el manifiesto para el canal ' . $rChannel);

            $rChannelData = getChannel($rChannel, $rScript);
            if (is_array($rChannelData))
            {
                $audioDelay=$rChannelData['audioDelay'];
                $rChannelData=$rChannelData['url'];
            }
        
            if ($rChannelData) {
                $rStarted = false;
                $rMemoryUsage = 0;
                $rFFPID = NULL;
                $rStreamInfo = NULL;
                $segmentsMap = new segmentsMapper(MAIN_DIR . 'video/' . $rChannel . '/final');
/*                $ipcMessenger = new ipcMessage();

                // LANZAMIENTO DE SERVIDOR DE VIDEO
                plog('Lanzando el servidor de segmentos para el canal de prueba');
                $res = startSegmentsServer($ipcMessenger, MAIN_DIR . 'video/' . $rChannel . '/final');
                if (array_key_exists('socket_address', $res) and array_key_exists('process', $res) and array_key_exists('pipes', $res)
                    and array_key_exists('pid', $res)) {
                    $VideoServerPid = $res['pid'];
                    $VideoServerprocess=$res['process'];
                    $VideoServerPipes=$res['pipes'];
                    $rDatabase = setCache($rDatabase, 'videoserver_pid', $VideoServerPid);
                    saveCache($rChannel, $rDatabase);
                    plog( 'Servidor iniciado en la direccion ' . $res['socket_address'] . ' PID ' . $VideoServerPid );

                }*/
                //   LANZAMIENTO DEL FFMPEG

                $ffmpegConversor = new FfmpegHlsConversor($rFFMpeg, MAIN_DIR . 'video/' . $rChannel . '/final', MAIN_DIR . 'hls/' . $rChannel . '/hls', $log = MAIN_DIR . 'logs/ffmpeg/' . $rChannel . '.log');
                $params = array(
                    'segmentTime' => 4,
                    'listSize' => 9,
                    'channel' => $rChannel
                );

                while (true) {
                    $rMemoryUsage = memory_get_usage();
                    plog('Memory usage: ' . round($rMemoryUsage / 1024 / 1024, 2) . ' MB');
                   # plog('DRM Processes: ' . getProcessCount());
                    $rKeyFail = false;
                    $rStart = round(microtime(true) * 1000);

                    if (!is_dir(MAIN_DIR . 'video/' . $rChannel . '/final')) {
                        plog('Detención forzada');
                        break;
                    }

                    plog('Obteniendo segmentos...');

                    do {
                        $rSegments = getStreamVideoAndAudioPartes($rChannelData, $numSegments, $rScript, $rLanguage, $maxvres);
                    } while (!$rSegments or !array_key_exists('segments', $rSegments) or !count($rSegments['segments']));

                    $segmentsMap->mapSegments($rSegments['segments']);
                    plog('Segmentos obtenidos del manifiesto: ' . count($segmentsMap->getSegmentsMap()));

                    if ($rSegments && strlen($rSegments['video'])) {
                        $rKeys = getKeyCache($rScript,$rChannel);

                        if (!$rKeys) {
                            foreach (range(1, 3) as $rRetry) {
                                plog('Solicitando key para el canal : ' . $rChannel);
                                if(array_key_exists('audioKID',$rSegments) and array_key_exists('videoKID',$rSegments)
                                    and !(0===strcmp($rSegments['audioKID'],$rSegments['videoKID'])))
                                    $rData = getKey($rScript, $rChannel,[$rSegments['audioKID'],$rSegments['videoKID']]);
                                else
                                    $rData = getKey($rScript, $rChannel);

                                if ($rData['status']) {

                                    $rKeys = $rData['key'];
                                    plog('¡Keys obtenidas!');
                                    if (!setKeyCache($rScript,$rChannel, $rData['key'])) {
                                        plog('[FATAL] No se puede escribir en la cache de keys. Cerrando el servidor para conservar la integridad.');
                                        exit();
                                    }
                                    break;
                                } else
                                    plog('[ERROR] Error al obtener la key, reintentando');
                                unset($rRetry, $rData);
                            }
                        } else
                            plog('Key del canal obtenida');
                        if ($rKeys) {
                       #     plog('Procesando ' . count($rSegments['segments']) . ' segmentos');
                            $rCompleted = $segmentsMap->processSegments($rKeys, $rSegments, $rVideoDir . '/' . $rChannel, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.63 Safari/537.36', $rScript,$audioDelay);

                            if ($rCompleted[1]) // Se procesó algun segmento nuevo
                            {
                                plog('Finished processing segments.');
                                $isFFmpegRunning = $ffmpegConversor->isRunning();
// COMPROBAR SI ESTA ANDANDO EL PROCESO FFMPEG, SI NO SE PARA, SE REINICIA EL SERVIDOR DE VIDEO Y SE REINICIA FFMPEG
                                if (!$isFFmpegRunning)
                                    #|| (file_exists(MAIN_DIR . ('hls/' . $rChannel . '/hls/playlist.m3u8')) && (60 <= time() - filemtime(MAIN_DIR . ('hls/' . $rChannel . '/hls/playlist.m3u8')))))
                                {
                                    plog('Conversion reiniciada.');
                                    exec('rm -f ' . MAIN_DIR . 'hls/' . $rChannel . '/hls/*');
                                    $responseMessage = $ffmpegConversor->startFfmpegInstance2($params);
                                    plog('INFO '.$responseMessage);

                                    $FFPID = $ffmpegConversor->getFfmpegPID();
                                    $rDatabase = setCache($rDatabase, 'ffmpeg_pid', $rFFPID);
                                    $rStarted = true;
                                    $isFFmpegRunning = $ffmpegConversor->isRunning();
                                } else {
                                    $FFPID = $ffmpegConversor->getFfmpegPID();
                                    plog('INFO: ffmpeg esta corriendo con el pid ' . $FFPID);
                                }
                                $ffmpegConversor->feedFfmpeg();
                                if (!$rStreamInfo || (strlen($rStreamInfo) == 0)) {
                                    plog('Obteniendo informacion del stream');
                                    $rStreamInfo = getStreamInfo($rChannel);

                                    if (128 < strlen($rStreamInfo))
                                        $rDatabase = setCache($rDatabase, 'stream_info', $rStreamInfo);
                                }
                                plog('Copiando la base de datos a un fichero');
                                saveCache($rChannel, $rDatabase);
                            } else {
                                plog('[INFO] No hay segmentos nuevos procesados, esperamos 1 segundo');
                                sleep(1);
                            }
                        } else
                            $rKeyFail = true;
                    } else
                        plog('[FATAL] No pudieron obtenerse los segmentos de ' . $rChannelData);

                    unset($rSegments, $rKeys);

                    if ($rKeyFail) {
                        plog('[FATAL] Fallo al obtener la key, esperando');
                        sleep($rKeyWait);
                    } else {
                        $rFinish = round(microtime(true) * 1000);
                        $rTaken = $rFinish - $rStart;
                        $sleep = ($rWait - $rTaken) * 1000;

                      #  if ($sleep >100000)
                       # {
                        #    plog('tomado: ' . $rTaken . ' sleep= ' . $sleep);
                         #   usleep($sleep);
                        #}
                        unset($rFinish, $rTaken);
                    }
                    unset($rStart, $rKeyFail);
                    #sleep(3);
                    gc_collect_cycles();
                    #    clearSegments($rChannel);
                }
            } else {
                plog('[FATAL] Fallo al obtener la url del canal ' . $rChannel);
                sleep($rFailWait);
            }

            plog('Terminado, limpiando');
            if ($rFFPID && file_exists('/proc/' . $rFFPID))
                exec('kill -9 ' . $rFFPID);

            unset($rMPD, $rStarted, $rFFPID, $rStreamInfo, $rMemoryUsage);
        }

        unset($rDatabase, $rWait, $rFailWait, $rKeyWait, $rChannel);
        plog('¡Adios!');
        gc_disable();
        exit();
    }
} else if (isset($argv[1]) && (strtoupper($argv[1]) == 'STOP') && isset($argv[2])) {

    $rChannel = $argv[2];
   # $rPID = getCache($rChannel,'php_pid');

/*    if (posix_kill(intval($rPID), 0)) {
        posix_kill(intval($rPID), SIGTERM);
        while(posix_kill(intval($rPID), 0))
        {
            usleep(300000);
            plog('Esperando a que termine el proceso principal, PID '.$rPID);
        }
    }*/
    exec('kill -15 `ps -ef | grep \'DRM_' . $rChannel . '\' | grep -v grep | awk \'{print $2}\'`');
    exec('rm -rf ' . MAIN_DIR . 'video/' . $rChannel);
    exec('rm -rf ' . MAIN_DIR . 'hls/' . $rChannel);
  #s
    unset($rChannel, $rPID);
}
?>
