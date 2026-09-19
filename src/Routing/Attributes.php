<?php // src/Routing/Attributes.php
namespace Kip\Routing;

#[\Attribute(\Attribute::TARGET_METHOD)] final class Get {}
#[\Attribute(\Attribute::TARGET_METHOD)] final class Post {}
#[\Attribute(\Attribute::TARGET_METHOD)] final class Put {}
#[\Attribute(\Attribute::TARGET_METHOD)] final class Delete {}
#[\Attribute(\Attribute::TARGET_METHOD)] final class Auth {}
