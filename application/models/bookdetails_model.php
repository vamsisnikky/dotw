<?php

namespace models;

/**
 * Description of bookdetails_model
 *
 * @author Kawin Glomjai <g.kawin@live.com>
 */
use core\Model;

class bookdetails_model extends Model
{

    public function request(&$post)
    {
        if (!isset($post['bookingCode']))
        {
            throw new Exception('Check your Booking id.');
        }

        foreach ($post['bookingCode'] as $code)
        {
            $post['request'][] = '<customer>'
                    . '<username>' . $post['Username'] . '</username>'
                    . '<password>' . hash('md5', $post['Password']) . '</password>'
                    . '<id>' . $post['AgentId'] . '</id>'
                    . '<source>1</source>'
                    . '<request command="getbookingdetails">'
                    . '<bookingDetails>'
                    . '<bookingType>1</bookingType>'
                    . '<bookingCode>' . $code . '</bookingCode>'
                    . '</bookingDetails>'
                    . '</request>'
                    . '</customer>';
        }
    }

}
