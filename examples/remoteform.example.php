<?php

  $loader = require __DIR__ . '/../vendor/autoload.php';
  use \Jonhoo\Browser\RemoteForm;

  $site = file_get_contents('http://www.fastmail.fm');
  $dom = new DOMDocument();
  $dom->loadHTML($site);
  $xpath = new DOMXpath($dom);
  $form = new RemoteForm($xpath->query('//form[@id="login"]')->item(0));
  $form->setAttributeByName('username', 'me@eml.cc');
  $form->setAttributeByName('password', 'mypass');
  var_dump($form->getParameters());
?>
