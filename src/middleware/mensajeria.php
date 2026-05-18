<?php

function puedeMandarMensajeA($emisor, $receptor) {
    $eNivel = (int)$emisor['nivel'];
    $rNivel = (int)$receptor['nivel'];
    $eArea  = $emisor['area'];
    $rArea  = $receptor['area'];

    if ($eNivel === 1 || $rNivel === 1) return true;

    if ($eNivel === 2) {
        if ($rNivel === 2) return true;
        if ($rNivel === 3 || $rNivel === 4) return $eArea === $rArea;
    }

    if ($eNivel === 3) {
        if ($rNivel === 2) return $eArea === $rArea;
        if ($rNivel === 3) return $eArea === $rArea;
    }

    if ($eNivel === 4) {
        if ($rNivel === 2) return $eArea === $rArea;
    }

    return false;
}

function puedeMandarAnuncio($emisor) {
    return (int)$emisor['nivel'] <= 2;
}