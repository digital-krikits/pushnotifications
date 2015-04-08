<?php namespace Digitalkrikits\Pushnotifications;

class Pushnotifications
{

    /**
     * @var string Message to be sent
     */
    private $message = '';

    /**
     * @var array Additional data
     */
    private $data = [];

    /**
     * @var array Android users to receive the notification
     */
    private $android = [];

    /**
     * @var array iOS users to receive the notification
     */
    private $ios = [];

    /**
     * @var array Result of the push notifications
     */
    private $result = [];

    /**
     * @var array APN response codes
     */
    private $apnResonses = [
        0 => 'No errors encountered',
        1 => 'Processing error',
        2 => 'Missing device token',
        3 => 'Missing topic',
        4 => 'Missing payload',
        5 => 'Invalid token size',
        6 => 'Invalid topic size',
        7 => 'Invalid payload size',
        8 => 'Invalid token',
        255 => 'None (unknown)',
    ];

    /**
     * Set the notification message
     * @param string $message
     */
    public function setMessage($message)
    {
        $this->message = $message;
    }

    /**
     * @param array $recipients
     */
    public function addRecipients(array $recipients)
    {
        foreach ($recipients as $to) {
            $this->addRecipient($to);
        }
    }

    /**
     * @param array $recipient
     */
    public function addRecipient(array $recipient)
    {
        if ($recipient['platform'] == 'android') {
            $this->android[] = $recipient['token'];
        } else if ($recipient['platform'] == 'ios') {
            $this->ios[] = $recipient['token'];
        }
    }

    /**
     * @param array $data
     */
    public function addData(array $data)
    {
        $key = key($data);
        $this->data[$key] = $data[$key];
    }

    /**
     * @param string $key
     */
    public function deleteData($key)
    {
        unset($this->data[$key]);
    }

    /**
     * @param array $data
     */
    public function setData(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param array $recipients
     * @param string $message
     * @param array $data
     * @return array
     */
    public function send($recipients = [], $message = '', $data = [])
    {
        if (count($recipients)) {
            $this->addRecipients($recipients);
        }
        if (strlen($message)) {
            $this->setMessage($message);
        }
        if (count($data)) {
            $this->setData($data);
        }
        $this->ios = array_unique($this->ios);
        $this->android = array_unique($this->android);

        if (count($this->ios)) {
            $this->ios();
        }
        if (count($this->android)) {
            $this->android();
        }
        return $this->result;
    }

    /**
     * Send to android devices
     */
    private function android()
    {
        $api_access_key = config('dkpush.android-access-key');

        $msg = [
            'message' => $this->message,
            'title' => config('dkpush.android-title')   // will be overwritten if defined in $data
        ];

        if (count($this->data)) {
            foreach ($this->data as $key => $value) {
                $msg[$key] = $value;
            }
        }

        $fields = [
            'registration_ids' => $this->android,
            'data' => $msg
        ];

        $headers = [
            'Authorization: key=' . $api_access_key,
            'Content-Type: application/json'
        ];

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, config('dkpush.android-url'));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
            $result = curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $e) {
        }
        $results = [];
        if (!isset($result['results'])) {
            foreach ($this->android as $token) {
                $results[$token] = ['status' => 'success'];
            }
        } else {
            foreach ($result['results'] as $res) {
                $results[$res['registration_id']] = ['status' => 'fail', 'message' => $res['message']];
            }
            foreach ($this->android as $token) {
                if (!array_key_exists($token, $results)) {
                    $results[$token] = ['status' => 'success'];
                }
            }
        }
        $this->result['android'] = $results;
    }

    /**
     * Send to iOS devices
     * @return bool
     */
    private function ios()
    {
        $passphrase = config('dkpush.ios-passphrase');
        $host = config('dkpush.ios-host');
        $cert = config('dkpush.ios-cert');

        $message = $this->message;

        $ctx = stream_context_create();
        stream_context_set_option($ctx, 'ssl', 'local_cert', $cert);
        stream_context_set_option($ctx, 'ssl', 'passphrase', $passphrase);

        $body['aps'] = [
            'alert' => $message,
            'sound' => 'default',
            'badge' => '+1',
        ];

        if (count($this->data)) {
            foreach ($this->data as $key => $value) {
                $body[$key] = $value;
            }
        }

        $payload = json_encode($body);

        $fp = stream_socket_client(
            $host, $err,
            $errstr, 60, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $ctx);

        if (!$fp) {
            $this->result['ios'] = 'Could not connect to host';
            return false;
        }

        foreach ($this->ios as $deviceToken) {

            $msg = chr(0) . @pack('n', 32) . @pack('H*', $deviceToken) . @pack('n', strlen($payload)) . $payload;

            $result = fwrite($fp, $msg, strlen($msg));

            if (!$result) {
                $this->result['ios'][$deviceToken] = ['status' => 'fail', 'message' => 'Payload couldn\'t be sent'];
            } else {


                $read = [$fp];
                $null = null;
                $changedStreams = stream_select($read, $null, $null, 0, 2000000);

                if ($changedStreams === false) {
                    $this->result['ios'][$deviceToken] = ['status' => 'fail', 'message' => 'Unable to wait for a stream availability'];
                } elseif ($changedStreams > 0) {

                    $responseBinary = fread($fp, 6);
                    if ($responseBinary !== false || strlen($responseBinary) == 6) {

                        if (!$responseBinary) {
                            $this->result['ios'][$deviceToken] = ['status' => 'success', 'message' => 'No response binary'];
                            continue;
                        }

                        $response = @unpack('Ccommand/Cstatus_code/Nidentifier', $responseBinary);

                        if ($response && $response['status_code'] > 0) {
                            $this->result['ios'][$deviceToken] = ['status' => 'fail', 'message' => $this->apnResonses[$response['status_code']]];
                            continue;
                        } else {
                            if (isset($response['status_code'])) {
                                $this->result['ios'][$deviceToken] = ['status' => 'success', 'message' => 'Status code: ' . $response['status_code']];
                            }
                        }

                    } else {
                        $this->result['ios'][$deviceToken] = ['status' => 'fail', 'message' => 'Invalid responseBinary: ' . $responseBinary];
                        continue;
                    }
                } else {
                    $this->result['ios'][$deviceToken] = ['status' => 'success', 'message' => 'No changed streams'];
                    continue;
                }


            }

        }

        fclose($fp);

    }
}
