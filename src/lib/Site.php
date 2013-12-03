<?php

class Site
{
    private $sitesRoot = '/var/www';
    private $nginxConfigRoot = '/etc/nginx';
    private $phpFpmConfigRoot = '/etc/php5/fpm';
    private $templatesDir = '../templates';
    private $homeDirectory = '';
    private $ownerGroup = 'www-data';
    private $userName = '';
    private $userNamePrefix = 'www-';
    private $dirsMode = 0760;

    private function executeCommand($command)
    {
        $descriptorspec = array(
            0 => array("pipe", "r"), // stdin
            1 => array("pipe", "w"), // stdout
            2 => array("pipe", "w"), // stderr
        );

        $process = proc_open($command, $descriptorspec, $pipes);
        if (is_resource($process)) {
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            if (!empty($stderr)) {
                trigger_error($stderr);
                return false;
            }

            proc_close($process);
        } else {
            trigger_error("Can't execute '" . $command . "' command");
            return false;
        }

        return true;
    }

    private function createUser()
    {
        assert('!empty($this->homeDirectory)');
        assert('!empty($this->userName)');

        $this->executeCommand(
            sprintf('adduser --system --home %s --disabled-password %s', $this->homeDirectory, $this->userName)
        );
    }

    private function checkRoot()
    {
        return 0 == posix_getuid();
    }

    private function setupDirectories($domain)
    {
        assert('!empty($this->homeDirectory)');
        assert('!empty($this->userName)');

        $dirs = [
            'root' => $this->homeDirectory,
            'www' => $this->homeDirectory . '/www',
            'tmp' => $this->homeDirectory . '/tmp',
        ];

        mkdir($dirs['www']);
        mkdir($dirs['tmp']);

        foreach ($dirs as $dir) {
            chmod($dir, $this->dirsMode);
            chown($dir, $this->userName);
            chgrp($dir, $this->ownerGroup);
        }
    }

    private function createNginxConfig($domain, $name)
    {
        $config = file_get_contents(__DIR__ . '/' . $this->templatesDir . '/nginx.conf');
        $outConfig = str_replace(array('[name]', '[domain]'), array($name, $domain), $config);

        $configName = $this->nginxConfigRoot . '/sites-available/' . $domain;
        file_put_contents($configName, $outConfig);
        symlink($configName, $this->nginxConfigRoot . '/sites-enabled/' . $domain);
    }

    private function createPhpFpmConfig($domain, $name)
    {
        $config = file_get_contents(__DIR__ . '/' . $this->templatesDir . '/php-fpm.conf');
        $outConfig = str_replace(
            array('[username]', '[name]', '[domain]'),
            array($this->userName, $name, $domain),
            $config
        );

        $configName = $this->phpFpmConfigRoot . '/pool.d/' . $domain . '.conf';
        file_put_contents($configName, $outConfig);
    }

    private function restartService($serviceName)
    {
        return $this->executeCommand('service ' . $serviceName . ' restart');
    }

    private function isValidDomain($domain)
    {
        return (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $domain) //valid chars check
            && preg_match("/^.{1,253}$/", $domain) //overall length check
            && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $domain)); //length of each label
    }

    function add($domain)
    {
        if (!$this->isValidDomain($domain)) {
            trigger_error('Invalid domain name');
            return false;
        }

        if (!$this->checkRoot()) {
            trigger_error('User is not root');
            return false;
        }

        $name = str_replace('.', '-', $domain);
        $this->homeDirectory = $this->sitesRoot . '/' . $domain;
        $this->userName = $this->userNamePrefix . $name;

        $this->createUser();

        $this->setupDirectories($domain);

        $this->createNginxConfig($domain, $name);
        $this->createPhpFpmConfig($domain, $name);

        if (!$this->restartService('nginx')) {
            return false;
        }

        if (!$this->restartService('php5-fpm')) {
            return false;
        }

        return true;
    }
}