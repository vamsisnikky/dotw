<?php

namespace models;

/**
 * Search_model : this file for model processes about getting a products and response own Travflex format.
 * Notice : other model are use some method for helping their process. If you need change something (such as logic,flow process) please make sure it dosen't effect with other model.
 * @author Kawin Glomjai <g.kawin@live.com>
 */
use core\Model;
use \SimpleXMLElement;
use \Exception;

class search_model extends Model
{

    var $idx_chk = 0;
    var $fetch_data_count = 0;
    var $chunk_hotel_list = 50;
    var $sessionid = NULL;
    var $suppliercode = '';
    private $filterModify = '';

    /**
     * construction and initilize.
     */
    public function __construct()
    {
        $CI = getInstance();
        $this->suppliercode = $CI->suppliercode;
        $this->sessionid = date('YmdHis') . rand(0, 999);
        unset($CI);
    }

    /**
     * Get hotels list in the city.
     * @param array $post
     * @return null
     * @throws Exception
     */
    public function fetchHotelList(array &$post)
    {
        if (isset($post["flagAvail"]) AND ( strtoupper($post["flagAvail"]) == 'N' OR strtoupper($post["flagAvail"]) == 'FALSE'))
        {
            throw new Exception('On request room doesn\'t support');
        }

        $strSQL = 'SELECT SpHotelCode'
                . ' FROM'
                . ' spcitys ci,'
                . ' spcountrys co, '
                . _SUPPLIERCODE . '_pfmappings pf'
                . ' WHERE'
                . ' pf.SupplierCode = "' . $this->suppliercode . '"'
                . ' AND co.SupplierCode = "' . $this->suppliercode . '"'
                . ' AND ci.SupplierCode = "' . $this->suppliercode . '"';

        if ($post['HotelId'] != NULL OR $post['HotelId'] != '')
        {
            $strSQL .= ' AND pf.SpCountry = co.SpCountryCode'
                    . ' AND pf.SpCity = ci.SpCityCode'
                    . ' AND pf.WSCode = "' . $post['HotelId'] . '"'
                    . ' GROUP BY pf.SpHotelCode';
        }
        else
        {
            $strSQL .= ' AND ci.WSCode = "' . $post['DestCity'] . '"'
                    . ' AND co.WSCode = "' . $post['DestCountry'] . '"'
                    . ' AND pf.SpCountry = co.SpCountryCode'
                    . ' AND pf.SpCity = ci.SpCityCode'
                    . ' LIMIT ' . $this->fetch_data_count . ',' . $this->chunk_hotel_list . "\n";
        }

        $rs = $this->db->query($strSQL);
        if ($rs === FALSE)
        {
            return;
        }

        //If not result, exit recursive process.
        if ($rs->num_rows() == 0)
        {
            return;
        }

        $result = $rs->result_array();
        foreach ($result as $k => $val)
        {
            $post['SpHotelList'][$this->idx_chk][] = $val['SpHotelCode'];
        }

        $this->fetch_data_count += $this->chunk_hotel_list;

        if ($post['HotelId'] == NULL OR $post['HotelId'] == '')
        {
            $this->idx_chk++;
            $this->fetchHotelList($post);
        }
    }

    /**
     * Build request use by xml style.
     * @param array $post
     * @throws Exception
     */
    public function searchHotelsRequest(array &$post)
    {
        if (!isset($post['SpHotelList']))
        {
            throw new Exception('Hotel not available in database, please check your HotelCode.');
        }

        //room request.
        $post['roomType'] = getRoomType($post);
        if (count($post['roomType']) > 9)
        {
            throw new Exception('Maximun 9 rooms per request.');
        }

        //paxpassport
        $paxId = @GetCountryCode($post['PaxPassport'], $this->suppliercode);
        if ($paxId == NULL OR $paxId == '')
        {
            throw new Exception('Please check your nationallity code. (mapping error)');
        }

        $aRequestRoomType = array();
        foreach ($post['roomType'] as $idx => $type)
        {
            //children
            $strRoomType_child = '';
            if ($type['children'] > 0)
            {
                for ($i = 1; $i <= $type['children']; $i++)
                {
                    $strRoomType_child .= '<child runno="' . $i . '">' . $type['age' . $i] . '</child>';
                }
            }

            $aRequestRoomType[] = '<room runno="' . $idx . '">'
                    . '<adultsCode>' . $type['adults'] . '</adultsCode>'
                    . '<children no="' . $type['children'] . '">'
                    . $strRoomType_child
                    . '</children>'
                    . '<rateBasis>-1</rateBasis>'
                    . '<passengerNationality>' . $paxId . '</passengerNationality>'
                    . '</room>';
        }

        //multi currency.
        list($post['AgentId'], $currency) = explode('#', $post['AgentId']);

        //build request 
        foreach ($post['SpHotelList'] as $h_chunk)
        {
            $post['request'][] = '<customer>'
                    . '<username>' . $post['Username'] . '</username>'
                    . '<password>' . hash('md5', $post['Password']) . '</password>'
                    . '<id>' . $post['AgentId'] . '</id>'
                    . '<source>1</source>'
                    . '<product>hotel</product>'
                    . '<request command="searchhotels">'
                    . '<bookingDetails>'
                    . '<fromDate>' . ($post['FromDt']) . '</fromDate>'
                    . '<toDate>' . ($post['ToDt']) . '</toDate>'
                    . '<currency>' . getCurrencyId($post, $currency) . '</currency>'
                    . '<rooms no="' . count($aRequestRoomType) . '">'
                    . implode('', $aRequestRoomType)
                    . '</rooms>'
                    . '</bookingDetails>'
                    . '<return>'
                    . '<getRooms>true</getRooms>'
                    . '<filters xmlns:a="http://us.dotwconnect.com/xsd/atomicCondition" xmlns:c="http://us.dotwconnect.com/xsd/complexCondition">'
                    . '<c:condition>'
                    . '<a:condition>'
                    . '<fieldName xmlns="">hotelId</fieldName>'
                    . '<fieldTest xmlns="">in</fieldTest>'
                    . '<fieldValues xmlns="">'
                    . '<fieldValue xmlns="">' . implode('</fieldValue><fieldValue xmlns="">', $h_chunk) . '</fieldValue>'
                    . '</fieldValues>'
                    . '</a:condition>'
                    . '</c:condition>'
                    . '</filters>'
                    . '</return>'
                    . '</request>'
                    . '</customer>';
        }
    }

    /**
     * call and insert products rate into database.
     * @param array $post
     * @return string
     */
    public function response(array $post)
    {
        if (function_exists('supplierCreateTemporary'))
        {
            $tmpSupplier = supplierCreateTemporary($this->sessionid);
        }

        $arrResultPost = array();
        foreach ($post['response']['content'] as $idx => $response)
        {
            $o_resp = parse_xml($response);
            if ((string) $o_resp->successful != 'TRUE')
            {
                log_message('warning', 'some query unable process. line : ' . __LINE__ . ', file : ' . __FILE__ . ', method : ' . __METHOD__);
                continue;
            }

            $arrResultPost = $this->parseHotel($o_resp, $post);
            $rs = $this->db->query(generateInsertbatch($arrResultPost, $tmpSupplier));
            if ($rs === FALSE)
            {
                log_message('warning', 'some query unable process.');
                continue;
            }
        }
        return $this->sessionid;
    }

    /**
     * set and sort prepare hold data before insert into database. 
     * @param SimpleXMLElement $o_resp
     * @param array $post
     * @return array
     */
    public function parseHotel(SimpleXMLElement $o_resp, array $post)
    {
        $hotels = $o_resp->xpath('//hotel');
        $hotels = array_filter($hotels);
        $_return = array();
        if (empty($hotels))
        {
            return array();
        }

        foreach ($hotels as $hotel)
        {
            //fix searchhotel and getroom compalitable
            if (isset($hotel->attributes()->hotelid))
            {
                $sphotelcode = (string) $hotel->attributes()->hotelid;
            }
            else
            {
                $sphotelcode = (string) $hotel->attributes()->id;
            }

            foreach ($post['roomType'] as $idxRoomType => $roomtype)
            {
                $query = './/room'
                        . '[@adults = ' . $roomtype['adults'] . ']'
                        . '[@children = ' . $roomtype['children'] . ']'
                        . '[@childrenages = "' . trim($this->getChildAgeRangeQuery($roomtype)->childrenages) . '"]'
                        . '[@runno = ' . $idxRoomType . ']'
                        . '/roomType'
                        . $this->getTwinBedQuery($roomtype)
                        . $this->getChildAgeRangeQuery($roomtype)->queryagelimit
                        . $this->getExtraBedQuery($roomtype, 'roomInfo')
                        . $this->getRoomTypeCodeQuery($post);


                $rooms = $hotel->xpath($query);
                $rooms = array_filter($rooms);
                if (empty($rooms))
                {
                    continue;
                }

                $a_hotelItem = array();
                foreach ($rooms as $room)
                {
                    $query = './/rateBasis'
                            . '[isBookable = "yes"]'
                            . '[onRequest = 0]'
                            . $this->getExtraBedQuery($roomtype, 'rateBasis')
                            . $this->getMealCodeQuery($post);

                    $rateBases = $room->xpath($query);
                    $rateBases = array_filter($rateBases);
                    if (empty($rateBases))
                    {
                        continue;
                    }

                    foreach ($rateBases as $rate)
                    {
                        $a_hotelItem[] = array(
                            'SessionId' => $this->sessionid,
                            'SpHotelCode' => $sphotelcode,
                            'RoomCatg' => (string) $room->attributes()->roomtypecode,
                            'RoomType' => $roomtype['shorttype'],
                            'BKF' => (string) $rate->attributes()->id,
                            'Avail' => 'Y',
                            'PriceTotal' => doubleval($rate->total),
                            'Currency' => (string) $rate->rateType->attributes()->currencyid,
                            'AdultNum' => $roomtype['adults'],
                            'ChildNum' => $roomtype['children'],
                            'ChildAge1' => $roomtype['age1'],
                            'ChildAge2' => $roomtype['age2'],
                            'CancelPolicyID' => base64_encode(json_encode(array(
                                'typecode' => (string) $room->attributes()->roomtypecode,
                                'bkf' => (string) $rate->attributes()->id))),
                            'MinAge' => intval($room->roomInfo->minChildAge),
                            'MaxAge' => intval($room->roomInfo->maxChildAge),
                            'ChildOverAge' => '',
                            'CanAmend' => 'N',
                            'CreateDt' => date('Y-m-d H:i:s'),
                            "HotelMessage" => ''
                        );
                    }
                }
                $_return = array_merge($_return, $this->getBestRoomPrice($a_hotelItem));
            }
        }
        return $_return;
    }

    /**
     * get best lowest price.
     * please make sure total price stay at right levels. if put it wrong level these results for return are bad result.
     * @param array $hotelItem
     * @return array
     */
    public function getBestRoomPrice(array $hotelItem)
    {

        $lowestprice = array();
        foreach ($hotelItem as $idx => $item)
        {
            $lowestprice[$item['RoomCatg'] . $item['BKF'] . $item['AdultNum'] . $item['ChildNum'] . $item['ChildAge1'] . $item['ChildAge2']][$idx] = $item['PriceTotal'];
        }

        $_return = array();
        foreach ($lowestprice as $item)
        {
            $_return[] = $hotelItem[array_search(min($item), $item)];
        }
        unset($lowestprice);
        return $_return;
    }

    /**
     * xpath query for check where are products acceptable twin room.
     * @param array $roomtype
     * @return string
     */
    public function getTwinBedQuery(array $roomtype)
    {
        if ($roomtype['shorttype'] == 'TW')
        {
            return '[boolean(twin) and twin = "yes"]';
        }
        return '';
    }

    /**
     * xpath query to get a extrabed available for thier items.
     * @param array $roomtype
     * @param type $_level
     * @return string
     * @throws Exception
     */
    public function getExtraBedQuery(array $roomtype, $_level = 'roomInfo')
    {
        if ($_level == '' OR $_level == NULL)
        {
            throw new Exception('Posible values : roomInfo , rateBasis (case sensitive).');
        }

        if (strtoupper($roomtype['rqbed']) == 'Y' AND strtolower($_level) == 'roominfo')
        {
            return '[roomInfo/maxExtraBed > 0]';
        }

        if (strtoupper($roomtype['rqbed']) == 'Y' AND strtolower($_level) == 'ratebasis')
        {
            return '[validForOccupancy/extraBed > 0]';
        }

        return '';
    }

    /**
     * xpath query for get a minimun range of child age.
     * @param array $roomtype
     * @return \stdClass
     */
    public function getChildAgeRangeQuery(array $roomtype)
    {
        $maxAge = array();
        $o = new \stdClass();
        $o->childrenages = '';
        $o->queryagelimit = '';
        if ($roomtype['children'] > 0)
        {
            //child age limitation.
            $o->childrenages = $roomtype['age1'] . ($roomtype['age2'] != '' ? ',' . $roomtype['age2'] : '');
            $maxAge[0] = $roomtype['age1'];
            $maxAge[1] = $roomtype['age2'];
            $maxAge = max($maxAge);
            $o->queryagelimit = '[roomInfo/maxChildAge >= ' . $maxAge . ']';
            unset($maxAge);
        }
        return $o;
    }

    /**
     * 
     * @param array $post
     * @return string
     */
    public function getRoomTypeCodeQuery(array $post)
    {
        $data = GetListRoomCatgCode($post['ServiceCode'], $this->suppliercode);
        if (count($data) == 0)
        {
            return '';
        }

        $query = '[';
        foreach ($data as $val)
        {
            $query .= '(@roomtypecode = "' . $val['SpCode'] . '") or ';
        }
        $query = substr($query, 0, -4) . "]";
        return $query;
    }

    /**
     * 
     * @param array $post
     * @return string
     */
    public function getMealCodeQuery(array $post)
    {
        $data = GetListMealTypeCode($post['ABFType'], $this->suppliercode);
        if (count($data) == 0)
        {
            return '';
        }

        $query = '[';
        foreach ($data as $val)
        {
            $query .= '(@id = "' . $val['SpCode'] . '") or ';
        }
        $query = substr($query, 0, -4) . "]";
        return $query;
    }

    /**
     * 
     * @param array $post
     * @param array $filters
     * @return null
     */
    public function setModifyFilter(array &$post, array $filters)
    {
        $filters = array_filter($filters);
        if (empty($filters))
        {
            return;
        }
        foreach ($post['request'] as &$request)
        {
            $o_xml = parse_xml($request);
            $o_filter = current($o_xml->xpath('//filters'));
            $c_cond = $o_filter->children('c', true);

            foreach ($filters as $filter)
            {
                if ($filter['operator'] != '')
                {
                    $c_cond->addChild('operator', $filter['operator'], '');
                }

                $a_cond = $c_cond->addChild('condition', null, 'http://us.dotwconnect.com/xsd/atomicCondition');
                foreach ($filter['condition'] as $key => $condition)
                {
                    if ($key == 'fieldValue')
                    {
                        $a_fieldValue = $a_cond->addChild('fieldValues', null, '');
                        foreach ($condition as $item)
                        {
                            $a_fieldValue->addChild('fieldValue', $item, '');
                        }
                    }
                    $a_cond->addChild($key, $condition, '');
                }
            }
            $request = formatXMLBeauty($o_xml);
        }
    }

    /**
     * 
     * @param array $post
     * @param string $keyItem
     * @param string $fieldTest
     * @return array
     */
    public function getFiltersItem(array $post, $keyItem, $fieldTest = 'in')
    {
        if ($keyItem == 'ServiceCode')
        {
            $fieldName = 'roomId';
            $data = GetListRoomCatgCode($post[$keyItem], $this->suppliercode);
        }

        if ($keyItem == 'ABFType')
        {
            $fieldName = 'roomRateBasis';
            $data = GetListMealTypeCode($post[$keyItem], $this->suppliercode);
        }

        if (count($data) == 0)
        {
            return array();
        }

        $fieldValues = array();
        foreach ($data as $value)
        {
            $fieldValues[] = $value['SpCode'];
        }


        if (count($fieldValues) == 1)
        {
            $fieldTest = 'equals';
        }

        return array(
            'operator' => 'AND',
            'condition' => array(
                'fieldName' => $fieldName,
                'fieldTest' => $fieldTest,
                'fieldValue' => $fieldValues
            )
        );
    }

}
