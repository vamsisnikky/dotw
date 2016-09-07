<?php

namespace models;

/**
 * Description of getrooms_model
 *
 * @author Kawin Glomjai <g.kawin@live.com>
 */
use core\Model;
use \Exception;

class getrooms_model extends Model
{

    public $search_model = NULL;
    private $groupRoom = array();
    private $request = array();
    private $suppliercode = '';

    public function __construct()
    {
        $CI = &getInstance();
        if (!isset($CI->search_model))
        {
            $CI->load->model('search_model');
            $this->search_model = $CI->search_model;
        }
        else
        {
            $this->search_model = $CI->search_model;
        }

        $this->suppliercode = $CI->suppliercode;
        unset($CI);
    }

    /**
     * 
     * @param array $post
     * @return array
     * @throws Exception
     */
    public function getQueryConditionPath(array $post)
    {
        $a_roomcategories = @GetListRoomCatgCode($post['RoomCatgWScode'], $this->suppliercode);
        if (empty($a_roomcategories))
        {
            throw new Exception('No room categories. (' . $post['RoomCatgWScode'] . ')');
        }

        $queryTypeCode = '';
        foreach ($a_roomcategories as $room)
        {
            $queryTypeCode .= '(@roomtypecode = "' . $room['SpCode'] . '") or ';
        }

        $a_bkf = @GetListMealTypeCode($post['BFType'], $this->suppliercode);
        if (empty($a_bkf))
        {
            throw new Exception('No meal type. (' . $post['BFType'] . ')');
        }
        $queryBKF = '';
        foreach ($a_bkf as $bkf)
        {
            $queryBKF .= '(@id= "' . $bkf['SpCode'] . '") or ';
        }
        return array('roomtypecode' => substr($queryTypeCode, 0, -4), 'bkf' => substr($queryBKF, 0, -4));
    }

    /**
     * Build queries filter room matching condition.
     * @param array $post
     * @return array
     * @throws Exception
     */
    public function getQueriesRoom(array $post, array $conditionPath)
    {
        $queries = array();
        foreach ($post['roomType'] as $idxRoomType => $roomtype)
        {
            $queries[] = '//room'
                    . '[@adults = ' . $roomtype['adults'] . ']'
                    . '[@children = ' . $roomtype['children'] . ']'
                    . '[@childrenages = "' . trim($this->search_model->getChildAgeRangeQuery($roomtype)->childrenages) . '"]'
                    . '[@runno = ' . $idxRoomType . ']'
                    . '/roomType'
                    . '[' . $conditionPath['roomtypecode'] . ']'
                    . $this->search_model->getTwinBedQuery($roomtype)
                    . $this->search_model->getChildAgeRangeQuery($roomtype)->queryagelimit
                    . $this->search_model->getExtraBedQuery($roomtype, 'roomInfo')
                    . '/rateBases/rateBasis'
                    . '[' . $conditionPath['bkf'] . ']'
                    . $this->search_model->getExtraBedQuery($roomtype, 'rateBasis');
        }
        return $queries;
    }

    /**
     * Build getRoom command xml.
     * @param type $post
     * @throws Exception
     */
    public function groupRoom(array $post)
    {
        if (!isset($post['response']['content']) OR empty($post['response']['content']))
        {
            throw new Exception('check your searchProduct it\'s work fine.');
        }

        if (empty($post['queries']))
        {
            throw new Exception('check room queries.');
        }

        $_return = array();
        foreach ($post['response']['content'] as $response)
        {
            $o_xml = parse_xml($response);
            if ((string) $o_xml->successful != 'TRUE')
            {
                throw new Exception("Search response from supplier was unacceptable.");
            }

            foreach ($post['queries'] as $query)
            {
                $rateBasis = $o_xml->xpath($query);
                $rateBasis = array_filter($rateBasis);
                if (empty($rateBasis))
                {
                    throw new Exception('RoomName : ' . $post['RoomCatgName'] . ' or Meal Type : ' . $post['BFType'] . ' not exists.');
                }

                //find min item.
                //unfortunately, Xpath find min algorithm doesn't work in this case.
                $token = array();
                foreach ($rateBasis as $rate)
                {
                    $token[(string) $rate->total] = $rate;
                }
                $min = min(array_keys($token));
                $rateBasis = $token[$min];
                unset($token);

                $_return[] = array(
                    'RoomCatg' => (string) current($rateBasis->xpath('../../@roomtypecode')),
                    'BKF' => (string) $rateBasis->attributes()->id,
                    'PriceTotal' => doubleval($rateBasis->total),
                    'allocationDetails' => (string) $rateBasis->allocationDetails,
                );
            }
        }
        return $_return;
    }

    /**
     * 
     * @param array $post
     * @param string $request
     * @throws Exception
     */
    public function request(array $post, $request, array $groupRooms)
    {
        if ($request == '' OR $request == NULL)
        {
            throw new Exception('check SearchHotel request.');
        }

        $o_modify = parse_xml($request);
        $o_modify->request->attributes()->command = 'getrooms';
        $o_modify->request->bookingDetails->productId = GetHotelCode($post['HotelId'], $this->suppliercode)->Code;
        //chech room type equal with matched products.
        if (count($o_modify->request->bookingDetails->rooms->room) != count($groupRooms))
        {
            throw new Exception('Number of room request not equal with product.');
        }

        //modify xml
        $o_token = new \ArrayIterator(new \ArrayObject($groupRooms));
        $idx = 0;
        foreach ($o_modify->request->bookingDetails->rooms->room as $room)
        {
            $token = $o_token->offsetGet($idx);
            $room->roomTypeSelected->code = $token['RoomCatg'];
            $room->roomTypeSelected->selectedRateBasis = $token['BKF'];
            $room->roomTypeSelected->allocationDetails = $token['allocationDetails'];
            $idx++;
        }
        unset($o_modify->request->return);
        return $o_modify->asXML();
    }

}
