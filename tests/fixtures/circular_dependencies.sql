-- Test fixture: Circular dependency between functions
-- Expected: Warning about circular dependency, manual intervention required
-- Both functions reference each other

CREATE FUNCTION func_a() RETURNS integer AS $$
    SELECT func_b() + 1;
$$ LANGUAGE sql IMMUTABLE;

CREATE FUNCTION func_b() RETURNS integer AS $$
    SELECT func_a() - 1;
$$ LANGUAGE sql IMMUTABLE;
