<?php
require_once 'functions.php';

/*
 * Encargada de mantener la relacion de segmentos ya procesados de un stream
 * Dado el identificador unico de un segmento tiene que ser capaz de asignar y almacenar la ruta del fichero
 * en disco que le corresponde al video que representa
 * */

class segmentsMapper
{
    private $filesMap;
    private $filesDirectory;
    private $downloadedIds;

    /*
     * Params:
     * dirFiles Ruta de los segmentos a mapear
     * */
    public function __construct($dirFiles)
    {
        $this->filesDirectory = $dirFiles;
        $this->filesMap = array();
        $this->downloadedIds = array();

    }

    /*
     * Params:
     * $segmentsArray Vector con pares ID->val donde ID es el identificador del elemento a mapear, val no es utilizado
     * para esta operacion; si está vacio no se modifica nada.
     * Solo mapearemos los nuevos segmentos no conocidos cuyo id sea superior al ultimo mapeado, ya que se supondra que
     * se identican con el tiempo de inicio indicado en el segmentTimeline del manifiesto DASH del que provienen
     * */
    public function mapSegments($segmentsArray): array
    {
        $finalSegmentsMap = array();
        $finalDownloadedIds = array();
        $lastMapID = 0; // Empezaremos por el ID 1
        $newMaps = 0;   // Numero de nuevos segmentos mapeados
        $lastKnownID = 0;  // ultimo id de segmento conocido

        // Si hay elementos a mapear les asignamos o bien el ID que ya tenian o el siguiente al ultimo asignado al
        // ultimo mapeado
        if (is_array($segmentsArray) and count($segmentsArray)) {
            // Observamos el ultimo id asignado
            if (count($this->filesMap)) {
                $lastMapID = end($this->filesMap);
                $lastKnownID = end(array_keys($this->filesMap));
            }
            foreach ($segmentsArray as $segmentID => $segmentInfo) {
                if ($segmentID > $lastKnownID && !array_key_exists($segmentID, $this->filesMap)) {
                    $lastMapID++;
                    $lastKnownID = $segmentID;
                    $finalSegmentsMap[$segmentID] = $lastMapID;
                    $finalDownloadedIds[$segmentID] = false;
                    $newMaps++;
                } else {
                    $finalSegmentsMap[$segmentID] = $this->filesMap[$segmentID];
                    $finalDownloadedIds[$segmentID] = $this->downloadedIds[$segmentID];
                }
            }
            $this->filesMap = $finalSegmentsMap;
            $this->downloadedIds = $finalDownloadedIds;
        }
        return $this->filesMap;
    }

    public function getmappedSegmentID($segmentID)
    {
        $return = NULL;
        if (array_key_exists($segmentID, $this->filesMap)) {
            $return = $this->filesMap[$segmentID];
        }
        return $return;
    }

    public function getSegmentsMap(): array
    {
        return $this->filesMap;
    }

    public function getFirstMappedID()
    {
        $firstAssignedID = current($this->filesMap);
        return $firstAssignedID;
    }

    public function isSegmentProcessed($segmentId)
    {


        return $this->downloadedIds[$segmentId];
    }

    function processSegments($rKey, $rSegments, $rDirectory, $rUA, $rServiceName, $audioDelay=0,$encodeAudio=False,$headers = NULL)
    {
        $rCompleted = 0;
        if (!$headers) {
            $headers = [
                'http' => ['method' => 'GET', 'header' => 'User-Agent: ' . $rUA . "\r\n"],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
            ];
        }

        if (!is_file($rDirectory . '/encrypted/init.audio.mp4') || !filesize($rDirectory . '/encrypted/init.audio.mp4'))
            downloadfile($rSegments['audio'], $rDirectory . '/encrypted/init.audio.mp4', true, $rUA, $headers);
        if (!is_file($rDirectory . '/encrypted/init.video.mp4') || !filesize($rDirectory . '/encrypted/init.video.mp4'))
            downloadfile($rSegments['video'], $rDirectory . '/encrypted/init.video.mp4', true, $rUA, $headers);

        $rDownloadPath = $rDirectory . '/aria/';

        // Descarga los segmentos de audio y los de video en el directorio final del canal
        foreach (['video', 'audio'] as $rType) {
            $rDownloads = [];    // Lista de URL a descargar, de audio o video
            $rDownloadMap = [];  // Pares de claves valor formadas por la url y el id que lo representa

            foreach ($rSegments['segments'] as $rSegmentID => $rSegment) {
                $segmentID = $this->getmappedSegmentID($rSegmentID);
                $rFinalPath = $rDirectory . '/final/' . $segmentID . '.mp4';
                // Solo queremos descargar los segmentos que no esten ya procesados
                if (!$this->isSegmentProcessed($rSegmentID)) {
                    $rDownloads[] = $rSegment[$rType];
                    $rDownloadMap[$rSegment[$rType]] = $segmentID;
                }
            }
            downloadfiles($rDownloads, $rDownloadPath, $rUA, $headers);
           // downloadFilesCurl($rDownloads, $rDownloadPath);

            foreach ($rDownloads as $rURL) {
                $rBaseName = parse_url(basename($rURL), PHP_URL_PATH);  // Nombre del fichero con el segmento en el servidor de origen
                #plog($rBaseName);
                $rMap = $rDownloadMap[$rURL];
                $rPath = $rDownloadPath . $rBaseName;
                if (is_file($rPath) && (0 < filesize($rPath))) {
                    #plog($rPath);
                    #plog('Descargado segmento en '.$rDirectory . '/encrypted/' . $rMap . '.' . $rType . '.m4s');
                    rename($rPath, $rDirectory . '/encrypted/' . $rMap . '.' . $rType . '.m4s');
                }
            }
        }


        foreach ($this->filesMap as $rSegmentID => $rSegment)
            if (!$this->isSegmentProcessed($rSegmentID)) {

                $rFinalPath = $rDirectory . '/final/' . $rSegment . '.ts';

                if (!is_file($rFinalPath)) {
                    // Desencriptamos los segmentos de video
                    if (is_file($rDirectory . '/encrypted/' . $rSegment . '.video.m4s')) {
                        $rVideoPath = $rDirectory . '/decrypted/' . $rSegment . '.video.mp4';

                        if ($rKey) {
                            exec('cat "' . $rDirectory . '/encrypted/init.video.mp4" "' . $rDirectory . '/encrypted/' . $rSegment . '.video.m4s" > "' . $rDirectory . '/encrypted/' . $rSegment . '.video.complete.m4s"');


                            if (is_array($rKey))
                                $key = $rKey[1];
                            else
                                $key = $rKey;


                            if (!decryptsegment($key, $rDirectory . '/encrypted/' . $rSegment . '.video.complete.m4s', $rVideoPath, $rServiceName))
                                plog('[ERROR] Fallo al desencriptar ' . $rDirectory . '/encrypted/' . $rSegment . '.video.complete.m4s');
                            else
                                unlink($rDirectory . '/encrypted/' . $rSegment . '.video.complete.m4s');

                        } else
                            exec('cat "' . $rDirectory . '/encrypted/init.video.mp4" "' . $rDirectory . '/encrypted/' . $rSegment . '.video.m4s" > "' . $rVideoPath . '"');
                    } else
                        plog('[ERROR] No hay lista de segmentos de video para combinar, queria el ' . $rSegmentID);

                    // Desencriptamos los segmentos de audio
                    if (is_file($rDirectory . '/encrypted/' . $rSegment . '.audio.m4s')) {

                        $rAudioPath = $rDirectory . '/decrypted/' . $rSegment . '.audio.mp4';
                        if ($rKey) {
                            exec('cat "' . $rDirectory . '/encrypted/init.audio.mp4" "' . $rDirectory . '/encrypted/' . $rSegment . '.audio.m4s" > "' . $rDirectory . '/encrypted/' . $rSegment . '.audio.complete.m4s"');
                            $rAudioKey = $rKey;

                            // Por defecto se supone que la key de audio es la primera de las obtenidas
                            if (is_array($rKey))
                                $rAudioKey = $rKey[0];
                            #plog('vamos a desencriptar '.$rDirectory . '/encrypted/' . $rSegment . '.audio.complete.m4s');

                            if (!decryptsegment($rAudioKey, $rDirectory . '/encrypted/' . $rSegment . '.audio.complete.m4s', $rAudioPath, $rServiceName))
                                plog('[ERROR] Error al desencriptar segmento de audio!');
                            else
                                unlink($rDirectory . '/encrypted/' . $rSegment . '.audio.complete.m4s');

                        } else
                            exec('cat "' . $rDirectory . '/encrypted/init.audio.mp4" "' . $rDirectory . '/encrypted/' . $rSegment . '.audio.m4s" > "' . $rAudioPath . '"');
                    } else {
                        plog('[ERROR] No hay lista de segmentos de audio para combinar');

                    }

                    if (is_file($rVideoPath) && is_file($rAudioPath)) {

                        if (combinesegment($rVideoPath, $rAudioPath, $rFinalPath,$audioDelay,$encodeAudio))
                            plog('Combined audio and video segments to ' . $rFinalPath);
                        else
                            plog('Some error happened when combining segments ' . $rVideoPath . ' and ' . $rAudioPath . ' to ' . $rFinalPath);
                    } else
                        plog('[ERROR] No hay segmentos que combinar!');
                    $this->downloadedIds[$rSegmentID] = true;
                    unlink($rDirectory . '/encrypted/' . $rSegment . '.video.m4s');
                    unlink($rDirectory . '/encrypted/' . $rSegment . '.audio.m4s');
                    unlink($rDirectory . '/encrypted/' . $rSegment . '.video.complete.m4s');
                    unlink($rDirectory . '/encrypted/' . $rSegment . '.audio.complete.m4s');
                    unlink($rVideoPath);
                    unlink($rAudioPath);
                    if (is_file($rFinalPath))
                        $rCompleted++;
                }
            }
        return [count($rDownloads), $rCompleted];
    }
}
