<?php
define("API_KEY","3_nJILE6pHcAcV_PzORmiO_Y1PYCxRz1ViQySoc_PP78KzgCSrDyvcWrnNeXeO3g9A");
define("API_DOMAIN","us1.gigya.com");
define("APP_KEY","ANxj7nGHee98");
define("APP_SECRET","H4MIsSWphyCYglk2oddke0g0w9oDqSy80e0W8yrIXQ/rouOddX4VGnldzC03uUhI");
// set to "file" if app secret is saved in file, or "env", if app secret is set as server env var:
define("KEY_SAVE_TYPE", "file"); // "file" / "env"
    // if KEY_LOCATION is set as "file", specify the path that you have located your key file at:
    define("KEY_PATH", $_SERVER["DOCUMENT_ROOT"] . "/../gig_key.txt"); // leave value blank if not used (if KEY_LOCATION is set to "env")
// Debug mode: 
// !!! NOTE : using debug mode will output all Gigya calls info to log, including PII. 
// use ONLY in DEV enc with fake users !!!
// Gigya module will still output  runtime errors to gigya.log, with DEBUG mode off.
define("GIGYA_DEBUG", false);