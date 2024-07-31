This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry method-attributes instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview

This package provides instrumentation for methods by allowing users to add them with the `#[WithSpan]` attribute.
It will create a span for the lifecycle of the method- adding the span to the current context, and ending it when the
method is finished. Any exceptions thrown will be added to the span with the `recordExcpetion` method.

This is done by using reflection to find the methods with the `#[WithSpan]` attribute, and then using the `hook` method
to create an observer for that particular method.


## Versions

* Tested on PHP 8.2 and 8.3 with success

## Configuration

The extension can be disabled
via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=method-attributes
```
