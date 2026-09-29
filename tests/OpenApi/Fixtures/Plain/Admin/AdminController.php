<?php // tests/OpenApi/Fixtures/Plain/Admin/AdminController.php

// DECOY: depth two in a plain source is not the router's controller shape; a
// plain source scans only top-level <Name>Controller.php files. If discovery
// ever requires this file, the throw below fails the run loudly.
throw new \RuntimeException('openapi discovery loaded the decoy Plain/Admin/AdminController.php');
