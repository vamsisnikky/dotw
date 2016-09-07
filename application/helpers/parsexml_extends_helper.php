<?php

function registerNamespace(SimpleXMLElement &$o)
{

    foreach ($o->getNamespaces(true) as $ns => $val)
    {
        if ($ns == '')
        {
            $ns = 'ns';
        }
        $o->registerXPathNamespace($ns, $val);
    }

    return $o;
}

function formatXMLBeauty(SimpleXMLElement $o)
{
    $dom = new DOMDocument('1.0');
    $dom->loadXML($o->asXML());
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    return $dom->saveXML();
}
