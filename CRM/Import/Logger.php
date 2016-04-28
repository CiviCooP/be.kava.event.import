<?php

class CRM_Import_Logger {

  private static $enabled = TRUE;
  private static $logfp;

  public static function log($line) {

    if (!static::$enabled) {
      return FALSE;
    }

    $logfp = &static::$logfp;

    // Als logbestand nog niet geopend is, openen (in append mode) + shutdown-functie toevoegen om het bestand netjes te sluiten als het script eindigt.
    if (empty($logfp)) {
      $logfp = fopen('/tmp/import.log', 'a+');

      register_shutdown_function(function () use ($logfp) {
        if (!empty($logfp)) {
          fclose($logfp);
          unset($logfp);
        }
      });
    }

    // Write to log
    $line = "[" . date('d-m-Y H:i:s') . "] " . $line . "\n";
    fwrite($logfp, $line);
  }

} 