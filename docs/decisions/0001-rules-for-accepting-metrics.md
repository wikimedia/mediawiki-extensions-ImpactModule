# Allow non-AQS data lookups in ImpactModule, subject to metric acceptance rules

## Status

Accepted (2026-09-15)

## Context and Problem Statement

ImpactModule is the new home for the metrics that GrowthExperiments computes and displays at
`Special:Impact`. [T433352](https://phabricator.wikimedia.org/T433352) reviewed every metric the
current implementation offers, together with the data sources available for each one. Some metrics
map onto an [Analytics Query
Service](https://doc.wikimedia.org/generated-data-platform/aqs/analytics-api/) (AQS) endpoint,
others do not: the total edit count is a single column read (`UserEditTracker::getUserEditCount()`),
the global edit count is available from CentralAuth, and the number of Thanks received needs a more
involved query, which the Thanks extension already wraps in a service (`ThanksQueryHelper`).

That review left one question open, which is recorded in
[T434222](https://phabricator.wikimedia.org/T434222): in the new Impact module, should non-AQS data
lookups be allowed, and if so, to what extent? Challenging lookups clearly belong in AQS, but
nothing says what makes a lookup challenging. Concretely: is it reasonable for ImpactModule to read
`user_editcount` from the database? To ask CentralAuth for the global edit count? To count the
Thanks a user received?

The two systems involved have different jobs. MediaWiki answers web requests from data it already
keeps at hand; AQS pre-computes the aggregates nobody can produce inside a request. Treating
MediaWiki as a compute engine is how the current implementation ended up with a pipeline of its own,
so the rules need to say which side of that split a given metric sits on.

That pipeline is composed of several jobs and maintenance scripts, which compute metric values,
write them into a database table, and are then read back when the values are needed. All of that has
to be maintained. We want the new module to be free of that complexity whichever data sources it
ends up using. The rules below therefore have two jobs: to draw the line described above, and to
keep this pipeline from growing back.

Defining the acceptable cost of a metric is the main scope of this decision. Beyond those two jobs,
a few other properties of a metric need the same treatment: who may see a computed value, what a
metric may depend on, and where its computation lives. The decision therefore states those as rules
alongside the cost rule, rather than leaving them to a separate policy.

## Decision Outcome

Non-AQS data lookups are allowed. Rather than naming an approved set of data sources, ImpactModule
accepts a metric when it follows all of the rules below.

1. **Metrics can be computed on a cold cache in a web request context**: All metrics need to be
   computable within a web request context, without having to defer to a job or a similar mechanism.
   In other words: a metric's cost must not grow with the subject's history. It also means that
   _all_ metrics together need to be computable within less than ~1 second (for now, this should be
   interpreted as the order of magnitude, not as a specific number to work with).
   * Example: Calculating "how many articles the user created" cannot be implemented as a
     SQL-powered metric on MediaWiki's databases, because such a query would need to go through all
     revisions the user has associated. It might be implemented as an AQS-powered metric, for
     example.
2. **Metrics must not create computation or storage that ImpactModule has to maintain** (both
   directly and indirectly): One of the reasons why GrowthExperiments' implementation is needlessly
   complicated is that it is composed of several jobs and maintenance scripts, which need to be
   maintained. Those scripts were computing the metric's value and writing it into a database table,
   which we then read from when the values were needed. Such complexity is what we want to get rid
   of. This means [`IMetric::computeMetric()`](../../src/Metrics/Metric/IMetric.php) must return the
   value and must not create storage or computation of its own. Specifically, a metric may not (1)
   write its result anywhere, (2) schedule work that produces its own result, or (3) perform a
   synchronous write to a primary database.
   * For clarity, metrics _are_ allowed to use
     [`MetricState::Pending`](../../src/Metrics/MetricState.php) to indicate the result will be
     available at a later date, but such a computation needs to happen fully outside of ImpactModule
     scope (for example, pageviews computation works this way).
3. **ImpactModule should not own details about metric computation**: ImpactModule should not be the
   place that actually computes metrics. It might hold code that formats a value in an appropriate
   format for displaying to the user, but the actual computation needs to be elsewhere.
   Specifically, this means ImpactModule is prohibited from querying database tables it doesn't own;
   all such queries need to be delegated to a service outside of ImpactModule, cf.
   `ThanksQueryHelper`. This can be done by:
   * calling MediaWiki core's services (for example, `UserEditTracker` would be acceptable),
   * calling services prepared by other extensions (for example, `ThanksQueryHelper`, although see
     _Notes_ below),
   * calling external APIs (such as AQS or the [Data
     Gateway](https://wikitech.wikimedia.org/wiki/Data_Gateway)),
   * or maybe in some other ways.
4. **Metrics should be viewable by logged out users**: Metrics might be displayed to anyone who asks
   for them; ImpactModule will not hold any permission management logic. Metric implementations need
   to assume the computation result can be viewed by anyone. This means metric computation needs to
   use "public" as the audience, and similar. For clarity: presentation might be different for
   different users (including voluntarily not showing certain figures to certain users). This rule
   is only about _computing_ the value itself, and that if users see metrics not displayed to them,
   it would not amount to a privacy issue (or similar).
5. **Metrics should not introduce any new hard dependencies**: Only ImpactModule's core logic can
   introduce new hard dependencies. Metrics can depend on external sources (cf. rule 3), but such
   dependencies need to be soft. If they are not met, an appropriate `MetricState` is returned, but
   ImpactModule as a whole does not fail.

## Notes

* A rule that says "metrics need to announce their capping to the rest of ImpactModule" was
  considered, with "properly displaying to users" being the main reasoning. However, capping is not
  the only user-facing limitation; ingestion delay
  ([`AqsClient::INGESTION_LAG_DAYS`](../../src/Aqs/AqsClient.php)) has similar issues. Let's leave
  that for future improvements of ImpactModule and possibly add this rule if we determine it
  appropriate in the future.
  * Adding such a rule _today_ would prevent a direct usage of `ThanksQueryHelper`. This is because
    it is capped by default, which would violate the rule that was considered, and running it
    uncapped would run into rule 1. This would probably have been fine, as a more appropriate API
    for Thanks would be provided within [T433983](https://phabricator.wikimedia.org/T433983)
    soon(ish). However, there are other issues, and we should probably deal with such
    constraints together.
