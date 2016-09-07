<?php

if (!function_exists('getSalutationsIds'))
{

    function getSalutationsIds($post, $cols = array())
    {
        if (!is_array($post) OR empty($post))
        {
            throw new Exception('check input data.');
        }

        $request = '<customer>'
                . '<username>' . $post['Username'] . '</username>'
                . '<password>' . hash('md5', $post['Password']) . '</password>'
                . '<id>' . $post['AgentId'] . '</id>'
                . '<source>1</source>'
                . '<request command="getsalutationsids"></request>'
                . '</customer>';
        return callStaticData($request);
    }

}

if (!function_exists('getSpecialRequestsIds'))
{

    function getSpecialRequestsIds($post)
    {
        if (!is_array($post) OR empty($post))
        {
            throw new Exception('check input data.');
        }

        $request = '<customer>'
                . '<username>' . $post['Username'] . '</username>'
                . '<password>' . hash('md5', $post['Password']) . '</password>'
                . '<id>' . $post['AgentId'] . '</id>'
                . '<source>1</source>'
                . '<product>hotel</product>'
                . '<request command="getspecialrequestsids"></request>'
                . '</customer>';

        return callStaticData($request);
    }

}

if (!function_exists('getCurrencyId'))
{

    function getCurrencyId(array $post, $currency)
    {
        if (!is_string($currency))
        {
            throw new Exception('Check currency code');
        }

        $request = '<customer>'
                . '<username>' . $post['Username'] . '</username>'
                . '<password>' . hash('md5', $post['Password']) . '</password>'
                . '<id>' . $post['AgentId'] . '</id>'
                . '<source>1</source>'
                . '<request command="getcurrenciesids"></request>'
                . '</customer>';
        $CI = getInstance();
        $response = $CI->httpcurl->open(config_item(_DOTW_STAGE))
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send(array($request));
        $o = parse_xml($response['content'][0]);
        unset($response);
        return ((string) current($o->xpath('//option[@shortcut = "' . $currency . '"]/@value')));
    }

}

if (!function_exists('callStaticData'))
{

    function callStaticData($request)
    {
        $CI = getInstance();
        $response = $CI->httpcurl->open(config_item(_DOTW_STAGE))
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send(array($request));

        $o = parse_xml($response['content'][0]);
        $opts = $o->xpath('//option');
        $opts = array_filter($opts);
        if (empty($opts))
        {
            return array();
        }

        $data = array();
        foreach ($opts as $opt)
        {
            $colName = str_replace(array('.', ' '), '', (string) $opt);
            $data[$colName] = (string) $opt->attributes()->value;
        }

        return $data;
    }

}