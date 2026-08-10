# ImpactModule
ImpactModule is an extension that gathers data about impact of users. It integrates with the Wikimedia Analytics service.

## How to add a metric

For now, this extension is intended to be the source of all metrics (this might
change in the future). To add a new metric:

1. Create a class in `src/Metrics/Metric/` implementing
   [`IMetric`](src/Metrics/Metric/IMetric.php):
   * Declare a `public const string ID` with the metric's ID. The ID is the
     public name of the metric: it is used to register and enable the metric,
     it keys the results returned by `MetricComputer::getMetrics()`, and it
     appears in log messages.
   * `isAvailableForUser()` decides whether the metric can be computed for a
     given user (for example, a metric might only make sense for registered
     users). When it returns `false`, `MetricComputer` skips the computation.
   * `computeMetric()` performs the computation and returns a
     [`MetricResult`](src/Metrics/MetricResult.php), typically via
     `MetricResult::ready( $value )`. Exceptions thrown here are caught and
     logged by `MetricComputer` (and rethrown when
     `$wgImpactModuleThrowOnFailures` is enabled), so there is no need to
     handle failures within the metric itself.
2. Register the metric in `MetricFactory::METRICS_SPECS`. The array key **must**
   be the same string as the class's `ID` constant. The value is an
   [ObjectFactory](https://www.mediawiki.org/wiki/ObjectFactory) specification;
   use its `services` key to inject any services the metric needs.
3. Enable the metric by adding its ID to `$wgImpactModuleEnabledMetrics`.

See [`ExampleMetric`](src/Metrics/Metric/ExampleMetric.php) for a minimal
example.
