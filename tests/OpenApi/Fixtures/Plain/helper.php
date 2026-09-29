<?php // tests/OpenApi/Fixtures/Plain/helper.php
// DECOY: not a <Name>Controller.php file. If openapi discovery ever loads it,
// this throw turns the mistake into a loud test error instead of silent output.
throw new \RuntimeException('openapi discovery loaded the decoy Plain/helper.php');
