<?php
  $site = file_get_contents('http://www.gmail.com');
  $dom = new DOMDocument();
  $dom->loadHTML($site);
  $xpath = new DOMXpath($dom);
  $form = new RemoteForm($xpath->query('//form[@name="gaia_loginform"]')->item(0));
  $form->setAttributeByName('Email', 'me@gmail.com');
  $form->setAttributeByName('Passwd', 'mypass');
  var_dump($form->getParameters());
?>