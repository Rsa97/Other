#!/usr/bin/php
<?php

define('AEL_FILE', '/etc/asterisk/dialplan/m-mobile-route-by-def.ael');

$homeRegion = 'Республика Коми';
$routesMap = [
    'Теле2' => 'tele2',
    'МТС' => 'mts',
    'Ростелеком' => 'rtk',
];
$defaultLocalRoute = 'other';
$globalRoute = 'sipnet';

function getPage($url)
{
    $curl = curl_init($url);
    curl_setopt_array(
        $curl,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYSTATUS => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'curl/7.47.0'
        ]
    );
    $page = curl_exec($curl);
    curl_close($curl);
    return $page;
}

function genRange($from, $to)
{
    $fromN = intval($from);
    $toN = intval($to);
    if ($fromN === 0 && $toN === 9) {
        return 'X';
    }
    if ($fromN === $toN) {
        return $fromN;
    }
    if (($fromN + 1) === $toN) {
        return "[{$fromN}{$toN}]";
    }
    if (($fromN + 2) === $toN) {
        return "[{$fromN}" . ($fromN + 1) . "{$toN}]";
    }
    return "[{$fromN}-{$toN}]";
}

function masks($prefix, $from, $to, &$list = [])
{
    // print "masks('" . $prefix . "', '" . $from . "', '" . $to . "', \@)\n";
    $i = 0;
    while (($i < strlen($from)) && ($from[$i] === $to[$i])) {
        $prefix .= $from[$i];
        $i++;
    }
    $from = substr($from, $i);
    $to = substr($to, $i);
    if ($from === '') {
        $list[] = $prefix;
        return;
    }
    if (preg_match('/^0+$/', $from) && preg_match('/^9+$/', $to)) {
        $list[] = $prefix . str_repeat('X', strlen($from));
        return $list;
    }
    $from1 = $from[0];
    $fromRest = substr($from, 1);
    $fromZeros = preg_match('/^0+$/', $fromRest);
    $to1 = $to[0];
    $toRest = substr($to, 1);
    $toNines = preg_match('/^9+$/', $toRest);
    if ($fromZeros && $toNines) {
        $list[] = $prefix . genRange($from1, $to1) . str_repeat('X', strlen($fromRest));;
        return $list;
    }
    if ($fromZeros) {
        $list[] = $prefix . genRange($from1, intval($to1) - 1) . str_repeat('X', strlen($toRest));
        masks($prefix . $to1, str_repeat('0', strlen($toRest)), $toRest, $list);
        return $list;
    }
    if ($toNines) {
        masks($prefix . $from1, $fromRest, str_repeat('9', strlen($fromRest)), $list);
        $list[] = $prefix . genRange(intval($from1) + 1, $to1) . str_repeat('X', strlen($fromRest));
        return $list;
    }
    if (strlen($fromRest) === 0) {
        $list[] = $prefix . genRange($from1, $to1);
        return $list;
    }
    masks($prefix . $from1, $fromRest, str_repeat('9', strlen($fromRest)), $list);
    if ((intval($from1) + 1) < (intval($to1) - 1)) {
        $list[] = $prefix . genRange(intval($from1) + 1, intval($to1) - 1) . str_repeat('X', strlen($fromRest));
    }
    masks($prefix . $to1, str_repeat('0', strlen($toRest)), $toRest, $list);
    return $list;
}

$json = getPage('http://rosreestr.subnets.ru/?get=json');
$def = json_decode($json, true);
if ($def === null) {
    print "Error loading DEF-file\n";
    exit;
}

$lists = [];
$masks = [];

foreach ($def as $block) {
    if ($block['region'] !== $homeRegion) {
        continue;
    }
    $masks[$block['operator']] = array_merge(
        $masks[$block['operator']] ?? [],
        masks('', "8{$block['code']}{$block['from']}", "8{$block['code']}{$block['to']}")
    );
}

$result = "macro mobile-route-by-def(number) {\n  switch (\${number}) {\n";
foreach ($masks as $operator => $extens) {
    $result .= "// {$operator}\n";
    $route = $routesMap[$operator] ?? $defaultLocalRoute;
    foreach ($extens as $exten) {
        $result .= "    pattern {$exten}:\n";
    }
    $result .= "      Return({$route});\n      break;\n";
}
$result .= "    default:\n      Return({$globalRoute});\n  }\n  return;\n}\n";
print $result;

$oldMd5 = md5(file_get_contents(AEL_FILE));
$newMd5 = md5($result);
if ($oldMd5 !== $newMd5) {
    file_put_contents(AEL_FILE, $result);
    `/usr/sbin/asterisk -x "ael reload"`;
}
