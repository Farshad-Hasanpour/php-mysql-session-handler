<?php
/**
 * this handler creates a new session if session do not exists or it is expired.
 **/
namespace my_session;
require_once 'SessionTrait.php';

final class MysqlSessionHandler implements \SessionHandlerInterface {
    use SessionTrait;
    //this attribute will be valued when read function is called
    private $session_id;
    private $token_lifetime; //lifetime of autologin token in seconds

    public function __construct(\PDO $db,int $token_days = 7){
        $this->conn = $db;
        if($this->conn->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION){
            $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        $this->expiry = time() + (int) ini_get('session.gc_maxlifetime');
        if($token_days > 30){
            $token_days = 30;
        }elseif($token_days <= 0){
            $token_days = 1;
        }
        $this->token_lifetime = time() + $token_days * 60 * 60 * 24;
    }

    /**
     * Close the session
     * @link http://php.net/manual/en/sessionhandlerinterface.close.php
     * @return bool <p>
     * The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     * </p>
     * @since 5.4.0
     */
    public function close(){
        try{
            //commit and release the lock of session by ending the transaction
            if($this->conn->inTransaction()){
                $this->conn->commit();
            }
            //garbage will be collected when gc function is runned and the condition is true
            if($this->collect_garbage){
                $query = "DELETE FROM $this->sess_table WHERE $this->sess_expiry < :time";
                $stmt = $this->conn->prepare($query);
                $now = time();
                $stmt->bindParam(':time', $now, \PDO::PARAM_INT);
                $stmt->execute();
                $this->collect_garbage = false;
            }
            return true;
        }catch(\PDOException $e){
            if($this->conn->inTransaction()){
                $this->conn->rollBack();
                $this->unset_cookie();
            }
            throw $e;
        }
    }

    /**
     * Destroy a session
     * @link http://php.net/manual/en/sessionhandlerinterface.destroy.php
     * @param string $session_id The session ID being destroyed.
     * @return bool <p>
     * The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     * </p>
     * @since 5.4.0
     */
    public function destroy($session_id){
        $query = "DELETE FROM $this->sess_table WHERE $this->sess_sid = :sid";
        try{
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':sid', $this->session_id);
            $stmt->execute();
        }catch(\PDOException $e){
            if($this->conn->inTransaction()){
                $this->conn->rollBack();
                $this->unset_cookie();
            }
            throw $e;
        }
        $this->unset_cookie();
        return true;
    }

    /**
     * Cleanup old sessions
     * @link http://php.net/manual/en/sessionhandlerinterface.gc.php
     * @param int $maxlifetime <p>
     * Sessions that have not updated for
     * the last maxlifetime seconds will be removed.
     * </p>
     * @return bool <p>
     * The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     * </p>
     * @since 5.4.0
     */
    public function gc($maxlifetime){
        //set the value to true so the next time that session is closed garbage will be collected
        $this->collect_garbage = true;
        return true;
    }

    /**
     * Initialize session
     * @link http://php.net/manual/en/sessionhandlerinterface.open.php
     * @param string $save_path The path where to store/retrieve the session.
     * @param string $name The session name.
     * @return bool <p>
     * The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     * </p>
     * @since 5.4.0
     */
    public function open($save_path, $name){
        return true;
    }

    /**
     * Read session data
     * @link http://php.net/manual/en/sessionhandlerinterface.read.php
     * @param string $session_id The session id to read data for.
     * @return string <p>
     * Returns an encoded string of the read data.
     * If nothing was read, it must return an empty string.
     * Note this value is returned internally to PHP for processing.
     * Note it is important to lock the session because the default php session does the same and
     * locks the file until it is closed
     * </p>
     * @since 5.4.0
     */
    public function read($session_id){
        // we are going to use $this->session_id from now on
        $this->session_id = $session_id;
        try{
            $this->conn->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->conn->beginTransaction();
            $result = $this->lock_session(true);
            if($result){
                // Return data if session is not expired;
                if ($result[$this->sess_expiry] > time()) {
                    return $result[$this->sess_data];
                }
            }
            // If there is no such session or it is expired so make a new session
            $this->initialize_session();
            // Lock new initialized session
            $this->lock_session(false);
            return '';
        }catch (\PDOException $e){
            if($this->conn->inTransaction()){
                $this->conn->rollBack();
                $this->unset_cookie();
            }
            throw $e;
        }
    }

    /**
     * Write session data
     * @link http://php.net/manual/en/sessionhandlerinterface.write.php
     * @param string $session_id The session id.
     * @param string $session_data <p>
     * The encoded session data. This data is the
     * result of the PHP internally encoding
     * the $_SESSION superglobal to a serialized
     * string and passing it as this parameter.
     * Please note sessions use an alternative serialization method.
     * </p>
     * @return bool <p>
     * The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     * </p>
     * @since 5.4.0
     */
    public function write($session_id, $session_data){
        try{
            $query = "INSERT INTO $this->sess_table ($this->sess_sid, $this->sess_expiry, $this->sess_data) 
                VALUES(:sid, :expiry, :data) ON DUPLICATE KEY UPDATE $this->sess_expiry = :expiry, 
                $this->sess_data = :data";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':expiry', $this->expiry, \PDO::PARAM_INT);
            $stmt->bindParam(':data', $session_data);
            $stmt->bindParam(':sid', $this->session_id);
            $stmt->execute();
            return true;
        }catch(\PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
                $this->unset_cookie();
            }
            throw $e;
        }
    }

    private function lock_session($fetch_also = false){
        try{
            $query = "SELECT $this->sess_expiry, $this->sess_data FROM $this->sess_table 
            WHERE sid = :sid FOR UPDATE";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':sid', $this->session_id);
            $stmt->execute();
            if($fetch_also){
                return $stmt->fetch(\PDO::FETCH_ASSOC);
            }
            return '';
        }catch(\PDOException $e){
            if($this->conn->inTransaction()){
                $this->conn->rollBack();
                $this->unset_cookie();
            }
            throw $e;
        }
    }

    private function initialize_session(){
        try{
            //creating new session id
            $new = new \SessionHandler();
            $this->session_id = $new->create_sid();
            //delete the cookie with expired session id
            $this->unset_cookie();
            //create new cookie for session with new id
            $params = session_get_cookie_params();
            setcookie($this->cookie_name, $this->session_id, $params['lifetime'], $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
            $query = "INSERT INTO $this->sess_table ($this->sess_sid, $this->sess_expiry, $this->sess_data) 
            VALUES (:sid, :expiry, :data)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':sid', $this->session_id);
            $stmt->bindParam(':expiry', $this->expiry, \PDO::PARAM_INT);
            $blank = '';
            $stmt->bindParam(':data', $blank);
            $stmt->execute();
        }catch(\PDOException $e){
            if($this->conn->inTransaction()){
                $this->conn->rollBack();
                $this->unset_cookie();
            }
            throw $e;
        }
    }

    private function unset_cookie(){
        $params = session_get_cookie_params();
        setcookie($this->cookie_name,'', time() - 86400, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
}