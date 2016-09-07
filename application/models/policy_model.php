<?php

namespace models;

/**
 * Description of policy_model
 *
 * @author Kawin Glomjai <g.kawin@live.com>
 */
use core\Model;
use \Exception;
use \DateTime;

class policy_model extends Model
{

    public $suppliercode = '';
    private $search_model = null;
    private $policies = array();
    private $cancelPolicyId = array();

    public function __construct()
    {
        $CI = getInstance();
        $this->suppliercode = $CI->suppliercode;
        $this->search_model = $CI->search_model;
        unset($CI);
    }

    public function __destruct()
    {
        $this->cancelPolicyId = array();
        $this->policies = array();
    }

    /**
     * Build queries filter room matching condition.
     * @param array $post
     * @return array
     * @throws Exception
     */
    public function getQueriesRoom(array $post)
    {
        $query_list = array();

        if (!isset($post['CancelPolicyID_decode']) OR empty($post['CancelPolicyID_decode']))
        {
            throw new Exception('Please decode your cancelpolicyid before use getpolicy method.');
        }

        //get booking policy.
        if (!isset($post['roomType']))
        {
            foreach ($post['CancelPolicyID_decode'] as $value)
            {
                $query_list[] = '//product'
                        . '[roomTypeCode= "' . $value['typecode'] . '"]'
                        . '[rateBasis= "' . $value['bkf'] . '"]'
                        . '[booked= "yes"]';
            }
            return $query_list;
        }

        //view policy process.
        foreach ($post['roomType'] as $idxRoomType => $roomtype)
        {
            foreach ($post['CancelPolicyID_decode'] as $value)
            {
                $query_list[] = '//room'
                        . '[@adults = ' . $roomtype['adults'] . ']'
                        . '[@children = ' . $roomtype['children'] . ']'
                        . '[@childrenages = "' . trim($this->search_model->getChildAgeRangeQuery($roomtype)->childrenages) . '"]'
                        . '[@runno = ' . $idxRoomType . ']'
                        . '/roomType'
                        . '[@roomtypecode = "' . $value['typecode'] . '"]'
                        . $this->search_model->getTwinBedQuery($roomtype)
                        . $this->search_model->getChildAgeRangeQuery($roomtype)->queryagelimit
                        . $this->search_model->getExtraBedQuery($roomtype, 'roomInfo')
                        . '/rateBases/rateBasis'
                        . '[@id="' . $value['bkf'] . '"]'
                        . '[isBookable = "yes"]'
                        . '[onRequest = 0]'
                        . $this->search_model->getExtraBedQuery($roomtype, 'rateBasis')
                        . '';
            }
        }
        return $query_list;
    }

    /**
     * Build query for filter cancellation policy response.
     * @return string
     */
    public function getQueryPolicy()
    {
        return './/cancellationRules/rule'
                . '[(noShowPolicy = "false" or not(boolean(noShowPolicy)))]'
                . '[cancelCharge != 0]';
    }

    /**
     * groupping policies in to array.
     * @param array $post
     * @param array $rateBasis
     * @throws Exception
     */
    public function groupPolicies(array $post, array $rateBasis)
    {
        if (empty($rateBasis))
        {
            return;
        }

        if (!isset($post['query_policy']))
        {
            throw new Exception('check policy XPath query.');
        }

        foreach ($rateBasis as $rate)
        {
            $key = (string) current($rate->xpath('../../@roomtypecode | .//roomTypeCode')) . (string) current($rate->xpath('./@id | .//rateBasis'));
            //query policy rules.
            $policiesRule = $rate->xpath($post['query_policy']);
            $policiesRule = array_filter($policiesRule);
            if (empty($policiesRule))
            {
                continue;
            }

            //get Meal type
            $bkf = @GetList21MealTypeCode((string) current($rate->xpath('./@id | .//rateBasis')), $this->suppliercode)->MealTypeCode;
            //get WSRoomCategory code 
            $oRoomcatg = @GetListWscodeRoomCatgs((string) current($rate->xpath('../../@roomtypecode | .//roomTypeCode')), $this->suppliercode);

            foreach ($policiesRule as $policy)
            {
                if (!isset($post['FromDt']))
                {
                    $post['FromDt'] = (string) $rate->from;
                }

                //Date and Excancel days.
                $o_fromdt = new DateTime(substr((string) $policy->fromDate, 0, 10));
                $o_todt = new DateTime($post['FromDt']);
                $exdays = $o_fromdt->diff($o_todt);

                //policied condition by cancellation charge.
                $key .= $o_fromdt->format('Y-m-d') . $o_todt->format('Y-m-d');
                
                if (array_key_exists($key, $this->policies))
                {
                    $this->policies[$key]['ChargeRate'] += doubleval($policy->cancelCharge);
                }
                else
                {
                    $this->policies[$key] = array(
                        'BFType' => $bkf,
                        'RoomCatgCode' => $oRoomcatg->wscode,
                        'RoomCatgName' => $oRoomcatg->SpLongName,
                        'FromDate' => $o_fromdt->format('Y-m-d'),
                        'ToDate' => $o_todt->format('Y-m-d'),
                        'ExCancelDays' => $exdays->format('%a'),
                        'ChargeType' => 'Amount',
                        'ChargeRate' => doubleval($policy->cancelCharge),
                        'Description' => '',
                        'Currency' => (string) current($rate->xpath('//currencyShort'))
                    );
                }
            }
            // Reason for break statement.
            //I REALLY hope, DOTW should be returned Best price t fist node items only.
            //However, for backtrack developer. don't wonder about that Break statement but if you able to get new algorithm for finding best price same with the search results. change it instantly ^_^.
            break;
        }
    }

    /**
     * Return grouped policies array values.
     * @return array
     */
    public function getPolicies()
    {
        $this->policies = array_values($this->policies);
        return $this->policies;
    }

    /**
     * Decode policy id to array and puts value to post reference variable.
     * @param array $post
     * @throws Exception
     */
    public function decodePolicyId(array &$post)
    {
        if (!isset($post['CancelPolicyID']) OR empty($post['CancelPolicyID']))
        {
            throw new Exception('Check cancel policy id.');
        }

        $post['CancelPolicyID_decode'] = array_map('base64_decode', explode('#|#', $post['CancelPolicyID']));
        foreach ($post['CancelPolicyID_decode'] as &$policy)
        {
            $policy = isJson($policy, true);
        }
    }

    /**
     * 
     * @param array $post
     * @return array
     */
    public function getRegroupPolicy(array $post)
    {
        if (!isset($post['CancelPolicyID_decode']))
        {
            throw new Exception('Call policy decoder first.');
        }

        $a_policies = array();
        foreach ($post['CancelPolicyID_decode'] as $policy)
        {
            $a_policies['roomId'][$policy['typecode']] = $policy['typecode'];
            $a_policies['roomRateBasis'][$policy['bkf']] = $policy['bkf'];
        }
        return $a_policies;
    }

    public function getModifyFilter(array $a_policies)
    {
        $a_policies = array_filter($a_policies);
        if (empty($a_policies))
        {
            throw new Exception('Check your filters condition.');
        }

        $filters = array();
        foreach ($a_policies as $key => $value)
        {
            $test = 'in';
            if (count($value) == 1)
            {
                $test = 'equals';
            }

            $filters[] = array(
                'operator' => 'AND',
                'condition' => array(
                    'fieldName' => $key,
                    'fieldTest' => $test,
                    'fieldValue' => array_values($value)
                )
            );
        }
        return $filters;
    }

}
