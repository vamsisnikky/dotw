<?php

namespace models\book;

/**
 * Prebook_model it was manage xml booking request for DOTW supplier.
 * Notice. this model was Dependency with search_model, please make sure its was exists.
 * Dependent model : search_model and getrooms_model
 * Sequence models call : search_model > getrooms_model
 * @author Kawin Glomjai <g.kawin@live.com>
 */
use core\Model;
use \Exception;
use \SimpleXMLElement;

class prebook_model extends Model
{

    var $suppliercode = '';
    var $satulationList = array();
    var $spRequest = array();
    var $search_model = null;

    /**
     * Construction for get dependent (search_model)
     * please make sure search_model was exists.
     */
    public function __construct()
    {
        $CI = getInstance();
        $this->suppliercode = $CI->suppliercode;
        $this->search_model = $CI->search_model;

        unset($CI);
    }

    /**
     * Create booking request for DOTW supplier.
     * @param array $post
     * @param type $mode
     * @throws Exception
     */
    public function getRequest(array &$post, $mode = 'confirmbooking')
    {
        list($post['AgentId'], $currency) = explode('#', $post['AgentId']);
        $this->satulationList = getSalutationsIds($post);
        $this->spRequest = getSpecialRequestsIds($post);

        if ($mode == '' OR $mode == NULL)
        {
            throw new Exception('Check your request mode. (Posible values: confirmbooking,savebooking)');
        }

        if (!isset($post['response']['content']) OR empty($post['response']['content']))
        {
            throw new Exception('check get rooms response.');
        }
        //one item loop
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        foreach ($post['response']['content'] as $idx => $resp)
        {
            $o_xml = parse_xml($resp);
            if ((string) $o_xml->successful != 'TRUE')
            {
                throw new Exception('data not found.');
            }

            $a_bookDetailRoom = $this->bookRoomDetailsRequest($post, $o_xml);

            $dom->loadXML('<customer>'
                    . '<username>' . $post['Username'] . '</username>'
                    . '<password>' . hash('md5', $post['Password']) . '</password>'
                    . '<id>' . $post['AgentId'] . '</id>'
                    . '<source>1</source> '
                    . '<product>hotel</product>'
                    . '<request command="' . $mode . '">'
                    . '<bookingDetails>'
                    . '<fromDate>' . ($post['FromDt']) . '</fromDate>'
                    . '<toDate>' . ($post['ToDt']) . '</toDate>'
                    . '<currency>' . getCurrencyId($post, $currency) . '</currency>'
                    . '<productId>' . GetHotelCode($post['HotelId'], $this->suppliercode)->Code . '</productId>'
                    . '<customerReference>' . $post['OSRefNo'] . '</customerReference>'
                    . '<rooms no="' . count($a_bookDetailRoom) . '">'
                    . implode('', $a_bookDetailRoom)
                    . '</rooms>'
                    . '</bookingDetails>'
                    . '</request>'
                    . ' </customer>');
            $post['request'] = array($dom->saveXML());
        }
    }

    /**
     * Create xml a part of booking request (Room request part).
     * @param array $post
     * @param SimpleXMLElement $o_xml
     * @return string
     * @throws Exception
     */
    protected function bookRoomDetailsRequest(array $post, SimpleXMLElement $o_xml)
    {
        if (!isset($post['roomType']) OR empty($post['roomType']))
        {
            throw new Exception('check getRoomType function.');
        }

        $a_bookDetailRoom = array();
        foreach ($post['roomType'] as $idx => $roomtype)
        {
            $query = '//hotel'
                    . '[@id = "' . GetHotelCode($post['HotelId'], $this->suppliercode)->Code . '"]'
                    . '[allowBook="yes"]'
                    . '/rooms/room'
                    . '[@adults = ' . $roomtype['adults'] . ']'
                    . '[@children = ' . $roomtype['children'] . ']'
                    . '[@childrenages = "' . trim($this->search_model->getChildAgeRangeQuery($roomtype)->childrenages) . '"]'
                    . '[@runno = "' . $idx . '"]'
                    . '/roomType'
                    . $this->search_model->getTwinBedQuery($roomtype)
                    . $this->search_model->getChildAgeRangeQuery($roomtype)->queryagelimit
                    . $this->search_model->getExtraBedQuery($roomtype, 'roomInfo')
                    . '/rateBases/rateBasis'
                    . '[status = "checked"]';
            $rooms = $o_xml->xpath($query);
            $rooms = array_filter($rooms);
            if (empty($rooms))
            {
                echo $o_xml->asXML();
                throw new Exception('No room categories rate, please check rate again. (' . $post['RoomCatgWScode'] . '), Query id : ' . $query);
            }

            //paxpassport
            $paxId = @GetCountryCode($post['PaxPassport'], $this->suppliercode);
            if ($paxId == NULL OR $paxId == '')
            {
                throw new Exception('Please check your nationallity code. (mapping error)');
            }

            //get current object.
            $room = current($rooms);

            $a_bookDetailRoom[] = '<room runno = "' . $idx . '">'
                    . '<roomTypeCode>' . (string) current($room->xpath('../../@roomtypecode')) . '</roomTypeCode>'
                    . '<selectedRateBasis>' . (string) $room->attributes()->id . '</selectedRateBasis>'
                    . '<allocationDetails>' . (string) $room->allocationDetails . '</allocationDetails>'
                    . $this->adultRequest($roomtype, $room)
                    . '<children no = "' . count($this->childrenRequest($roomtype)->children) . '">'
                    . implode('', $this->childrenRequest($roomtype)->children)
                    . '</children>'
                    . '<actualChildren no="' . count($this->childrenRequest($roomtype)->actualChildren) . '">'
                    . implode('', $this->childrenRequest($roomtype)->actualChildren)
                    . '</actualChildren>'
                    . $this->extraBedRequest($roomtype, $room)
                    . '<passengerNationality>' . $paxId . '</passengerNationality>'
                    . '<passengersDetails>'
                    . $this->passengersRequest($post, $idx)
                    . '</passengersDetails>'
                    . $this->specialitem($post)
                    . '</room>';
        }
        return $a_bookDetailRoom;
    }

    /**
     * Create xml a part of booking request (passengers request part).
     * @param array $post
     * @param type $idx
     * @return string
     */
    private function passengersRequest(array $post, $idx)
    {
        //passenger
        $strPassengers = '';
        foreach ($post['Rooms'][$idx]['PaxInformation'] as $idx_no => $pax)
        {

            if ($idx_no == 0)
            {
                $strPassengers .= '<passenger leading = "yes">';
            }
            else
            {
                $strPassengers .= '<passenger leading = "no">';
            }

            //protect when satulation not exists.
            if (!array_key_exists($pax->prefixName, $this->satulationList))
            {
                $satulation = $this->satulationList['Mr'];
            }
            else
            {
                $satulation = $this->satulationList[$pax->prefixName];
            }

            $strPassengers .= '<salutation>' . $satulation . '</salutation>'
                    . '<firstName>' . $pax->name . '</firstName>'
                    . '<lastName>' . $pax->surName . '</lastName>'
                    . '</passenger>';
        }

        return $strPassengers;
    }

    /**
     * Create xml a part of booking request (extrabed request part).
     * @param array $roomtype
     * @return string
     */
    private function extraBedRequest(array $roomtype, SimpleXMLElement $room)
    {
        //exbeds
        $strExbed = '<extraBed>0</extraBed>';
        if ($roomtype['rqbed'] == 'Y')
        {
            $strExbed = '<extraBed>1</extraBed>';
        }

        if (isset($room->validForOccupancy->extraBed) AND intval($room->validForOccupancy->extraBed) == 1)
        {
            $strExbed = '<extraBed>1</extraBed>';
        }
        return $strExbed;
    }

    /**
     * 
     * @param array $roomtype
     * @param SimpleXMLElement $room
     * @return string
     */
    private function adultRequest(array $roomtype, SimpleXMLElement $room)
    {
        $strAdult = '<adultsCode>' . $roomtype['adults'] . '</adultsCode>'
                . '<actualAdults>' . $roomtype['adults'] . '</actualAdults>';

        if (isset($room->validForOccupancy->extraBed) AND intval($room->validForOccupancy->extraBed) == 1)
        {
            $strAdult = '<adultsCode>' . intval($room->validForOccupancy->adults) . '</adultsCode>'
                    . '<actualAdults>' . $roomtype['adults'] . '</actualAdults>';
        }

        return $strAdult;
    }

    /**
     * Create xml a part of booking request (childern request part).
     * @param array $roomtype
     * @return \stdClass
     */
    private function childrenRequest(array $roomtype)
    {
        //children
        $o = new \stdClass();
        if ($roomtype['children'] > 0)
        {
            for ($i = 0; $i < $roomtype['children']; $i++)
            {
                if ($roomtype['rqbed'] == 'Y' AND $i == 0)
                {
                    $o->actualChildren[] = '<actualChild runno="' . $i . '">' . $roomtype['age' . ($i + 1 )] . '</actualChild>';
                    continue;
                }
                $o->children[] = '<child runno="' . $i . '">' . $roomtype['age' . ($i + 1 )] . '</child>';
            }
        }
        return $o;
    }

    /**
     * Create xml a part of booking request (Special Items request part).
     * @param array $post
     * @return string
     */
    private function specialitem(array $post)
    {
        //request special item
        if (empty($post['RequestDes']))
        {
            return '<specialRequests count="0"></specialRequests>';
        }

        $aSpecialItem = array();
        if (strpos($post['RequestDes'], 'Late Check-out'))
        {
            $aSpecialItem[] = $this->spRequest['RequestforaLateCheckOut'];
        }

        if (strpos($post['RequestDes'], 'Early Check-in'))
        {
            $aSpecialItem[] = $this->spRequest['RequestforanEarlyCheckIn'];
        }

        if (strpos($post['RequestDes'], 'Interconnecting Rooms'))
        {
            $aSpecialItem[] = $this->spRequest['RequestInterconnectingRooms'];
        }

        if (strpos($post['RequestDes'], 'Non-Smoking'))
        {
            $aSpecialItem[] = $this->spRequest['RequireaNonSmokingRoom'];
        }

        if (strpos($post['RequestDes'], 'High Floor'))
        {
            $aSpecialItem[] = $this->spRequest['RequestRoomonaHighFloor'];
        }

        if (strpos($post['RequestDes'], 'Low Floor'))
        {
            $aSpecialItem[] = $this->spRequest['RequestRoomonaLowFloor'];
        }

        if (strpos($post['RequestDes'], 'Honeymooners'))
        {
            $aSpecialItem[] = $this->spRequest['PleasenotethatGuestsareaHoneymoonCouple'];
        }

        if (strpos($post['RequestDes'], 'Cot(s)'))
        {
            $aSpecialItem[] = $this->spRequest['RequestforaBabyCot'];
        }

        if (empty($aSpecialItem))
        {
            return '<specialRequests count="0"></specialRequests>';
        }

        $data = '<specialRequests count="' . count($aSpecialItem) . '">';
        foreach ($aSpecialItem as $idx => $spitem)
        {
            $data .='<req runno="' . $idx . '">' . $spitem . '</req>';
        }
        $data .='</specialRequests>';
        unset($aSpecialItem);
        return $data;
    }

}
