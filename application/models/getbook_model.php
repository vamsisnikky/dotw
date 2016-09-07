<?php

namespace models;

/**
 * Description of getbook_model
 *
 * @author Kawin Glomjai <g.kawin@live.com>
 */
use core\Model;
use \Exception;

class getbook_model extends Model
{

    public function getRequest(array &$post)
    {

        if (empty($post['bookItinerary']) OR ! is_numeric(key($post['bookItinerary'])))
        {
            throw new Exception('data response not found.');
        }

        //15827 : 2015-06-18
        //Foreach loop bug.
        //changee loop to for.

        $post['request'] = array();
        list($post['AgentId']) = explode('#', $post['AgentId']);
        $cntBookIti = count($post['bookItinerary']);
        for ($i = 0; $i < $cntBookIti; $i++)
        {
            $post['request'][] = '<customer>'
                    . '<username>' . $post['Username'] . '</username>'
                    . '<password>' . hash('md5', $post['Password']) . '</password>'
                    . '<id>' . $post['AgentId'] . '</id>'
                    . '<source>1</source>'
                    . '<request command="getbookingdetails">'
                    . '<bookingDetails>'
                    . '<bookingType>1</bookingType>'
                    . '<bookingCode>' . $post['bookItinerary'][$i] . '</bookingCode>'
                    . '</bookingDetails>'
                    . '</request>'
                    . '</customer>';
        }
    }

}
