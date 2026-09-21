-- Uninstall removes only JoomEngine MCP tables, in dependency order.
DROP TABLE IF EXISTS "#__joomengine_mcp_audit";
DROP TABLE IF EXISTS "#__joomengine_mcp_session";
DROP TABLE IF EXISTS "#__joomengine_mcp_lease";
DROP TABLE IF EXISTS "#__joomengine_mcp_artifact";
DROP TABLE IF EXISTS "#__joomengine_mcp_job";
DROP TABLE IF EXISTS "#__joomengine_mcp_execution";
DROP TABLE IF EXISTS "#__joomengine_mcp_plan";
DROP TABLE IF EXISTS "#__joomengine_mcp_grant";
DROP TABLE IF EXISTS "#__joomengine_mcp_permission_request";
DROP TABLE IF EXISTS "#__joomengine_mcp_target";
DROP TABLE IF EXISTS "#__joomengine_mcp_prompt";
DROP TABLE IF EXISTS "#__joomengine_mcp_resource";
DROP TABLE IF EXISTS "#__joomengine_mcp_tool";
DROP TABLE IF EXISTS "#__joomengine_mcp_binding";
DROP TABLE IF EXISTS "#__joomengine_mcp_action";
DROP TABLE IF EXISTS "#__joomengine_mcp_schema";
DROP TABLE IF EXISTS "#__joomengine_mcp_provider";
