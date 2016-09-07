<?php

namespace models\book;

/**
 * Description of postbook_model
 *
 * @author Kawin Glomjai <g.kawin@live.com>
 */
use core\Model;
use \Exception;
use \SimpleXMLElement;

class postbook_model extends Model
{

    public $suppliercode = '';
    private $cacheBookingId = array();
    private $cacheBookOnRequest = array();

    /**
     * supplier booking status id 
     * @var int 
     */
    private $bookStatusId = 2;

    public function __construct()
    {
        $CI = getInstance();
        $this->suppliercode = $CI->suppliercode;
    }

    /**
     * 
     * @param array $post
     * @return type
     * @throws Exception
     */
    public function getResponse(array $post)
    {
        if (empty($post['response']['content']))
        {
            throw new Exception('booking details problem, please contact supplier (' . date(DATE_ATOM) . ')');
        }

        $resp = current($post['response']['content']);
        $o_xml = parse_xml($resp);
        unset($o_xml->confirmationText);
        if (strtoupper((string) $o_xml->successful) != "TRUE")
        {
            throw new Exception('supplier response error.');
        }

        $a_comp = $this->getCompleteService($post, $o_xml);
        $a_comp['RoomCatg'] = $this->getRoomCatg($post, $o_xml);
        $a_comp['RoomCatg'][0]['Room'] = $this->getRoomType($post, $o_xml);

        foreach ($a_comp['RoomCatg'][0]['Room'] as &$night)
        {
            $night['NightPrice'] = $this->getNightPrice($night['TotalPrice'], $post);
        }

        return $a_comp;
    }

    /**
     * part of header for booking, you can modify Head Booking Id and Reference in this here.
     * @param array $post
     * @param SimpleXMLElement $o_xml
     * @return array
     */
    public function getCompleteService(array $post, SimpleXMLElement $o_xml)
    {
        //Amendment status
        $status = 'CONF';
        if ((isset($post['OrgResId']) AND $post['OrgResId'] != '') AND ( isset($post['OrgHBId']) AND $post['OrgHBId'] != ''))
        {
            $status = 'AMENDCONF';
        }

        //bookingCode
        $bookingCode = $o_xml->xpath('//booking');
        foreach ($bookingCode as &$id)
        {
            $id = array(
                'runno' => (int) $id->attributes()->runno,
                'bC' => (string) $id->bookingCode
            );
        }

        $bookingReferenceNumber = $o_xml->xpath('//booking');
        foreach ($bookingReferenceNumber as &$id)
        {
            $id = (string) $id->bookingReferenceNumber;
        }

        //get currency
        $currency = @GetList21CurrencyCode((string) current($o_xml->xpath('//currency')), $this->suppliercode)->SPShortName;

        $arrCompleteService = array(
            'Id' => base64_encode(json_encode($bookingCode)),
            'RefHBId' => implode(',', $bookingReferenceNumber),
            'CanAmend' => 'True',
            'VoucherNo' => 'NONE',
            'Message' => '',
            'EMG' => '',
            'VoucherDt' => date('Y-m-d'),
            'RPCurrency' => $currency,
            'Status' => $status,
            'InternalCode' => _SUPPLIERCODE,
            'HotelId' => $post['HotelId'],
            'HotelName' => htmlspecialchars((string) GetHotelCode($post['HotelId'], GetSupplierCode())->SpHotelName),
            'FromDt' => $post['FromDt'],
            'ToDt' => $post['ToDt'],
            'RoomCatg' => array()
        );
        return $arrCompleteService;
    }

    /**
     * part of room category.
     * @param array $post
     * @param SimpleXMLElement $o_xml
     * @return array
     */
    private function getRoomCatg(array $post, SimpleXMLElement $o_xml)
    {
        $arrRoomCatg[0] = array(
            'CatgId' => $post['RoomCatgWScode'],
            'CatgName' => $post['RoomCatgName'],
            'Market' => '',
            'Avail' => 'Y',
            'BFType' => $post['BFType'],
            'RequestDes' => $post['RequestDes'],
            'Room' => array(),
        );
        return $arrRoomCatg;
    }

    /**
     * part of put Netprice and groupping room information in this array.
     * @param array $post
     * @param SimpleXMLElement $o_xml
     * @return type
     */
    private function getRoomType(array $post, SimpleXMLElement $o_xml)
    {
        $arrRooms = array();
        foreach ($post['Rooms'] as $idxRoom => $room)
        {
            $Totalprice = doubleval(current($o_xml->xpath('//booking[@runno = "' . $idxRoom . '"]/price/text()')));

            $arrRooms[$idxRoom] = array(
                "ServiceNo" => date("YmdHis") . rand(0, 999),
                "RoomType" => $room['RoomTypeName'],
                "SeqNo" => intval($idxRoom + 1),
                "AdultNum" => intval($room['AdultNum']),
                "ChildAge1" => $room['Age1'] == "0" ? "" : intval($room['Age1']),
                "ChildAge2" => $room['Age2'] == "0" ? "" : intval($room['Age2']),
                "TotalPrice" => $Totalprice,
                "CommissionPrice" => "0.00",
                "NetPrice" => $Totalprice,
                "NightPrice" => array(),
                "PaxInformation" => $room['PaxInformation_request']
            );
        }
        return $arrRooms;
    }

    /**
     * part of calcuation night price.
     * @param type $AvgPriceRoom
     * @param array $post
     * @return string
     */
    private function getNightPrice($AvgPriceRoom, array $post)
    {
        $arrNightPrice = array();
        if ($AvgPriceRoom > 0)
        {
            $indexNight = 0;
            while ($indexNight < $post['Night'])
            {
                $arrNightPrice[$indexNight] = Array
                    (
                    "AccomPrice" => cal_Price($AvgPriceRoom / $post['Night']),
                    "ChildMinAge" => 2,
                    "ChildMaxAge" => 18,
                    "ChildInfo" => "",
                    "MinstayDay" => "0.00",
                    "MinstayType" => "NONE",
                    "MinstayRate" => "0.00",
                    "MinstayPrice" => "0.00",
                    "CompulsoryName" => "NONE",
                    "CompulsoryPrice" => "0.00",
                    "SupplementName" => "NONE",
                    "SupplementPrice" => "0.00",
                    "PromotionName" => "NONE",
                    "PromotionValue" => "False",
                    "PromotionBFPrice" => "0",
                    "EarlyBirdType" => "NONE",
                    "EarlyBirdRate" => "0.00",
                    "EarlyBirdPrice" => "0.00",
                    "CommissionType" => "NONE",
                    "CommissionRate" => "0.00",
                    "CommissionPrice" => "0.00"
                );
                $indexNight++;
            }
        }
        return $arrNightPrice;
    }

    /**
     * Create Itinerary and caching booking id using for Auto cancel.
     * @param array $post
     * @throws Exception
     */
    public function getBookingItinerary(array &$post)
    {
        if (empty($post['response']['content']))
        {
            throw new Exception('booking problem, please contact supplier (' . date(DATE_ATOM) . ')');
        }

        $resp = current($post['response']['content']);
        $o_xml = parse_xml($resp);
        if (strtoupper((string) $o_xml->successful) != "TRUE")
        {
            throw new Exception('supplier response error.');
        }


        $post['bookItinerary'] = $o_xml->xpath('//booking'
                . '[bookingStatus = ' . $this->getBookStatusId() . ']'
                . '/bookingCode/text()');

        if (count($post['bookItinerary']) != count($post['Rooms']))
        {
            //get all booking token.
            $post['bookItinerary'] = $o_xml->xpath('//booking/bookingCode');
            foreach ($post['bookItinerary'] as $bookDetail)
            {
                $this->setCacheBookingId((string) $bookDetail);
                $this->setCacheBookOnRequest((string) $bookDetail);
            }
            return false;
            //throw new Exception('Some itinerary was OnRequest status, through Auto cancel mode.');
        }

        //reassignment array for getBookingDetails.
        foreach ($post['bookItinerary'] as &$bookDetail)
        {
            $bookDetail = (string) $bookDetail;
        }
        unset($o_xml);
    }

    /**
     * collect booking id data for auto cancel.
     * @param mix $cacheBookingId
     * @return void
     */
    public function setCacheBookingId($cacheBookingId)
    {
        if (is_array($cacheBookingId))
        {
            array_merge($this->cacheBookingId, $cacheBookingId);
            return;
        }
        array_push($this->cacheBookingId, $cacheBookingId);
        return;
    }

    /**
     * return collection data of booking id.
     * @return type
     */
    public function getCacheBookingId()
    {
        return $this->cacheBookingId;
    }

    /**
     * get booking status id.
     * @return int
     */
    public function getBookStatusId()
    {
        return $this->bookStatusId;
    }

    /**
     * config supplier booking status
     * [1 : On Request]
     * [2 : Confirm]
     * this system was set default value as 2.
     * ======[Reference from document :: 2015-06-09]======
     * more info,please visit : http://us.dotwconnect.com
     * @param $bookStatusId
     */
    public function setBookStatusId($bookStatusId = 2)
    {
        $this->bookStatusId = $bookStatusId;
    }

    public function getCacheBookOnRequest()
    {
        return $this->cacheBookOnRequest;
    }

    public function setCacheBookOnRequest($cacheBookOnRequest)
    {
        array_push($this->cacheBookOnRequest, $cacheBookOnRequest);
    }

}
