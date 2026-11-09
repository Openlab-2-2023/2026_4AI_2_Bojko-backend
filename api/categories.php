<?php
// Endpoints for categories.

// GET /api/categories
function listCategories(): never
{
    sendJson(findAllCategories());
}
