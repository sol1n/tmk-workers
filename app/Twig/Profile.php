<?php


namespace App\Twig;

use Illuminate\Foundation\Application;

class Profile extends \Twig_Extension
{
    /**
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * @param \Illuminate\Foundation\Application
     */
    public function __construct()
    {
    }

    public function getName()
    {
        return 'Twig_Extension_Profile_Helper';
    }

    public function getFunctions()
    {
        return [
            new \Twig_SimpleFunction('profileColor', array($this, 'profileColor'))
        ];
    }

    /**
     * Returns PageHelper instance from app container
     * @return \App\Helpers\PageHelper|null
     */
    public function profileColor($id)
    {
        $profileColors = [
            "#F44336",
            "#E91E63",
            "#9C27B0",
            "#673AB7",
            "#3F51B5",
            "#2196F3",
            "#03A9F4",
            "#00BCD4",
            "#009688",
            "#4CAF50",
            "#8BC34A",
            "#CDDC39",
            "#FFC107",
            "#FF9800",
            "#FF5722",
            "#03A9F4"
        ];

        if ($id) {
            $d = substr($id, -1);
            return $profileColors[intval($d, 16)];
        }
        return $profileColors[0];
    }
}
