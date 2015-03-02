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
     * @param array $to
     * @param string $message
     * @param array $data
     */
    public function send(array $to, $message, array $data = [])
    {
        $this->data = $data;
        $this->message = $message;


        foreach ($to as $recipient) {
            if ($recipient['platform'] == 'android') {
                $this->android[] = $recipient['token'];
            } else if ($recipient['platform'] == 'ios') {
                $this->ios[] = $recipient['token'];
            }
        }

        if (count($this->ios)) {
            $this->ios();
        }

        if (count($this->android)) {
            $this->android();
        }
        return $this->result;
    }

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
        $this->result['android'] = $result;
        // Log if needed or wanted
    }

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
            $this->result['ios'][] = 'Could not connect to host';
            return false;
        }

        foreach ($this->ios as $deviceToken) {

            $msg = chr(0) . @pack('n', 32) . @pack('H*', $deviceToken) . @pack('n', strlen($payload)) . $payload;

            $result = fwrite($fp, $msg, strlen($msg));

            if (!$result) {
                $this->result['ios'][] = 'FAIL: ' . $deviceToken;
            } else {
                $this->result['ios'][] = 'SUCCESS: ' . $deviceToken;
            }
        }

        fclose($fp);
    }
}
