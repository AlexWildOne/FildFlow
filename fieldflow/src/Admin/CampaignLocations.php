<?php

class CampaignLocations {
    // ... Existing methods and properties ...

    public function build_week_plan($base_date) {
        // Implement planning for Monday–Friday of the week containing week_start
        // Skip weekends/holidays within that week only
        // Include visits for monthly-frequency items based on resolve_monthly_week_indexes
        // Populate excluded_days and excluded_holidays
        // ... New implementation ...
    }

    public function build_month_plan($base_date) {
        // Refactor to use build_month_business_calendar() instead of ISO week keys
        // Ensure month weeks based on Monday-Friday buckets inside the month
        // Include week_start/week_end
        // Preserve existing monthly distribution logic and limit weekly items per available dates
        // ... New implementation ...
    }

    public function build_month_business_calendar() {
        // New method implementation based on existing surgical file
    }

    public function resolve_monthly_week_indexes() {
        // New method implementation based on existing surgical file
    }

    public function normalize_spread_indexes() {
        // New method implementation based on existing surgical file
    }

    public function build_reinforcement_week_order() {
        // New method implementation based on existing surgical file
    }

    public function find_bucket_key_for_date($date) {
        // New method implementation based on existing surgical file
    }

    public function bucket_index_by_key($key) {
        // New method implementation based on existing surgical file
    }

    public function adjust_reinforcement_recommendation_logic() {
        // Adjust the recommendation logic
        // Recommended should be true only when there are unassigned visits
        // Merge reinforcement summaries
        // Preserve all other code unchanged
    }

    // ... Other existing methods ...
}