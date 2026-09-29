<?php // tests/OpenApi/Fixtures/Features/Billing/views/show.php

// DECOY: a feature tree also carries views, migrations and tests; discovery
// reads only <Name>/<Name>Controller.php and must never load this.
throw new \RuntimeException('openapi discovery loaded the decoy Billing view');
