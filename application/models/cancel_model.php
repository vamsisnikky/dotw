<?php

namespace models;

/**
 * Description of cancel_model
 *
 * @author Kawin Glomjai <g.kawin@live.com>

 */
use core\Model;
use \Exception;

class cancel_model extends Model
{

    public function getRequest(&$post, $isComfirm = 'no')
    {
        if (!isset($post['bookItinerary']))
        {
            throw new Exception('BookingCode not exists.');
        }

        //clean up argument
        $isComfirm = strtolower(trim($isComfirm));

        //15827 : 2015-06-18
        //Foreach loop bug.
        //changee loop to for.
        $post['request'] = array();
        $cntBookIti = count($post['bookItinerary']);
        for ($i = 0; $i < $cntBookIti; $i++)
        {
            $testPriceAllocate = '';
            if (isset($post['response']['content'][$i]))
            {
                $testPriceAllocate = $this->getTestPanalty($post['response']['content'][$i], $isComfirm);
            }
            $AgentId = explode('#', $post['AgentId']);
            $post['request'][] = '<customer>'
                    . '<username>' . $post['Username'] . '</username>'
                    . '<password>' . hash('md5', $post['Password']) . '</password>'
                    . '<id>' . $AgentId[0] . '</id>'
                    . '<source>1</source>'
                    . '<request command="cancelbooking">'
                    . '<bookingDetails>'
                    . '<bookingType>1</bookingType>'
                    . '<bookingCode>' . $post['bookItinerary'][$i] . '</bookingCode>'
                    . '<confirm>' . $isComfirm . '</confirm>'
                    . $testPriceAllocate
                    . '</bookingDetails>'
                    . '</request>'
                    . '</customer>';
        }
    }

    public function getTestPanalty($response, $isComfirm = 'no')
    {

        if ($isComfirm != 'yes')
        {
            return;
        }

        $o_xml = parse_xml($response);
        if (strtoupper((string) $o_xml->successful) != 'TRUE')
        {
            throw new Exception('incompleted cancelled.');
            log_message('NOTICE', 'incompleted cancelled.', $o_xml);
        }

        $referencenumber = current($o_xml->xpath('//@code'));
        $penaltyApplied = doubleval(current($o_xml->xpath('//charge')));

        return '<testPricesAndAllocation>'
                . '<service referencenumber="' . $referencenumber . '">'
                . '<penaltyApplied>' . $penaltyApplied . '</penaltyApplied>'
                . '</service>'
                . '</testPricesAndAllocation>';
    }

    public function checkConfirmCancelled($post)
    {
        if (empty($post['response']['content']))
        {
            throw new Exception('incompleted cancelled.');
        }

        foreach ($post['response']['content'] as $idx => $response)
        {
            $o_xml = parse_xml($response);
            if (strtoupper((string) $o_xml->successful) != 'TRUE')
            {
                log_message('NOTICE', 'incompleted cancelled.', $response);
                throw new Exception('incompleted cancelled.');
            }
        }
    }

}
