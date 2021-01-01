<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpImap\Exceptions\InvalidParameterException;
use PhpImap\IncomingMail;
use PhpImap\Mailbox;

/**
 * Class Organize
 * @package App\Console\Commands
 */
class Organize extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:organize {ip?} {port=993} {username?} {domain?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Organize your IMAP inbox';
    /**
     * @var Mailbox
     */
    private Mailbox $connection;
    /** @var array $mailboxes */
    private array $mailboxes;
    /** @var array $config */
    private array $config = [];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param string $domain
     * @return false|int
     */
    private function validateDomain(string $domain)
    {
        return (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $domain) //valid chars check
            && preg_match("/^.{1,253}$/", $domain) //overall length check
            && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $domain)); //length of each label
    }

    /**
     * @param string $ip
     * @return mixed
     */
    private function validateIp(string $ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP);
    }

    /**
     *
     */
    private function setConfig()
    {
        $ip = (string)$this->argument('ip');
        while (!($this->validateDomain($ip) || $this->validateIp($ip))) {
            $this->error('Please input a valid domain name or IP address.');
            $ip = (string)$this->ask('Please input your mail server domain/ip.');
        }
        $this->config['ip'] = $ip;
        $port = $this->argument('port');
        while (!is_numeric($port)) {
            $this->error('Please input a valid port.');
            $port = $this->ask('Please input your mail server port.', $port);
        }
        $this->config['port'] = $port;
        $username = (string)$this->argument('username');
        while (!(strlen($username) > 0)) {
            $this->error('Please input a valid server username.');
            $username = $this->ask('Please input your mail server username.');
        }
        $this->config['username'] = $username;
        $this->config['password'] = $this->secret('Please input your mail server password.');

        $domain = (string)$this->argument('domain');
        while (!$this->validateDomain($domain)) {
            $this->error('Please input the domain to match.');
            $domain = (string)$this->ask('Please input the domain to match.');
        }
        $this->config['domain'] = $domain;
    }

    /**
     * @param string|null $config
     * @return array|mixed|string
     */
    private function getConfig(string $config = null)
    {
        $configuration = [
            'domain'      => '{' . $this->config['ip'] . ':' . $this->config['port'] . '/imap/ssl}INBOX',
            'username'    => $this->config['username'],
            'password'    => $this->config['password'],
            'attachments' => storage_path('attachments'),
            'encoding'    => 'UTF-8'
        ];
        if (is_null($config)) {
            return $configuration;
        }
        return $configuration[$config] ?? '';
    }

    /**
     * @throws InvalidParameterException
     */
    public function connect()
    {
        $this->connection = new Mailbox($this->getConfig('domain'), $this->getConfig('username'), $this->getConfig('password'), $this->getConfig('attachments'), $this->getConfig('encoding'));
    }

    /**
     * @return array
     */
    public function loadMailboxes(): array
    {
        $this->mailboxes = $this->connection->getMailboxes('*');
        return $this->mailboxes;
    }

    /**
     * Execute the console command.
     *
     * @throws InvalidParameterException
     */
    public function handle()
    {
        $this->setConfig();
        $this->connect();
        $this->loadMailboxes();
        foreach ($this->connection->searchMailbox('ALL') as $mailId) {
            $mail = $this->connection->getMail($mailId);
            if ($mailBoxName = $this->getMailbox($mail)) {
                $this->log('Email moved to ' . $mailBoxName);
                $this->connection->moveMail($mailId, $mailBoxName);
            } else {
                $this->error('Unknown email ' . $mail->toString);
            }
        }
    }

    /**
     * @param string $message
     */
    public function log(string $message): void
    {
        Log::channel('imaporganizer')->info($message);
        $this->info($message);
    }

    /**
     * @param IncomingMail $mail
     * @return false|string
     */
    private function getMailbox(IncomingMail $mail)
    {
        $from = strtolower(key($mail->to));
        if (Str::endsWith($from, '@' . $this->config['domain'])) {
            $mailBoxName = $this->generateMailboxName($from);
            return $this->searchMailBox($mailBoxName);
        }

        preg_match_all('/([^: ]+): (.+?(?:\r\n\s(?:.+?))*)\r\n/m',
            $mail->headersRaw, $matches);
        $headers = array_combine($matches[1], $matches[2]);

        if (isset($headers['Envelope-to'])) {
            $from = $headers['Envelope-to'];
            $from = explode(',', $from);
            $from = strtolower(trim($from[0]));
            if (Str::endsWith($from, '@' . $this->config['domain'])) {
                $mailBoxName = $this->generateMailboxName($from);
                return $this->searchMailBox($mailBoxName);
            }
        }
        return FALSE;
    }

    /**
     * @param string $mailBoxName
     * @return string
     */
    private function searchMailBox(string $mailBoxName): string
    {
        foreach ($this->mailboxes as $mailBox) {
            if (Str::endsWith($mailBox['shortpath'], '.' . $mailBoxName)) {
                return $mailBox['shortpath'];
            }
        }
        $this->log('Mailbox created ' . $mailBoxName);
        $this->connection->createMailbox($mailBoxName);
        $this->loadMailboxes();
        return $this->searchMailBox($mailBoxName);
    }

    /**
     * @param string|null $from
     * @return string
     */
    private function generateMailboxName(?string $from): string
    {
        return str_replace('.', '_', $from);
    }
}
