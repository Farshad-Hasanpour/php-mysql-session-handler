<?php

namespace my_session;


trait SessionTrait{
    //database data
    protected $database_name = 'mysession';
    protected $sess_table = 'sessions';
    // Nedded columns of sessions table
    protected $sess_sid = 'sid';
    protected $sess_expiry = 'expiry';
    protected $sess_data = 'data';
    //
    protected $expiry;
    protected $conn;
    protected $collect_garbage = false;
    protected $cookie_name = 'My_Session_Lib';
    //autologin cookie information
    protected $cookie_autologin_path = 'my_session';
    protected $cookie_autologin_secure = false;
    protected $cookie_autologin_httponly = true;
    protected $cookie_autologin_domain = 'localhost';
    protected $cookie_autologin_name = 'Autologin_Token';
}