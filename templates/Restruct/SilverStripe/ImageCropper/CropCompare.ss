<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <% base_tag %>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$Title</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .image-box {
            display: inline-block;
            background: repeating-conic-gradient(#e8e8e8 0% 25%, #fff 0% 50%) 50% / 16px 16px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 8px;
        }
        .image-box img {
            display: block;
            max-width: 250px;
            max-height: 200px;
        }
        .result-cell .image-box img {
            max-width: 150px;
            max-height: 120px;
        }
        .cropped-row td:first-child {
            border-left: 3px solid #28a745;
        }
        .focuspoint-row td:first-child {
            border-left: 3px solid #ffc107;
        }
        .cropped-row.focuspoint-row td:first-child {
            border-left: 3px solid #28a745;
            border-image: linear-gradient(to bottom, #28a745 50%, #ffc107 50%) 1;
        }
        .setup-divider::before,
        .setup-divider::after {
            content: '';
            display: inline-block;
            width: 80px;
            height: 1px;
            background: #dee2e6;
            vertical-align: middle;
            margin: 0 15px;
        }
        .crop-data {
            font-family: monospace;
            font-size: 11px;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 4px;
            max-height: 80px;
            overflow: auto;
        }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid py-4" style="max-width: 1800px;">

<% if $ShowSetup %>
    <%-- SETUP PAGE --%>
    <div class="card mx-auto text-center" style="max-width: 600px; margin-top: 40px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-4">Crop Functionality Test</h1>

            <% if $Error %>
                <div class="alert alert-danger">$Error</div>
            <% end_if %>

            <p class="text-muted">This tool tests crop functionality with SVG and PNG images, comparing regular manipulations with Cropped* versions.</p>

            <div class="mt-4">
                <p><strong>Option 1:</strong> Install test images with sample CropData</p>
                <p class="small text-muted mb-3">
                    <%-- Without SVG support only the PNG sample is installed (#6) --%>
                    <% if $SVGSupported %>
                    Creates 2 test files (SVG + PNG) in <code>assets/$TestFolder/</code><br>
                    <% else %>
                    Creates a PNG test file in <code>assets/$TestFolder/</code><br>
                    <% end_if %>
                    with pre-configured CropData for testing
                </p>
                <% if not $SVGSupported %>
                <p class="small text-muted mb-3 svg-unsupported-note">
                    The SVG sample needs <code>restruct/silverstripe-svg-images</code> (with <code>svg</code> allowed in <code>File.allowed_extensions</code>) and is skipped.
                </p>
                <% end_if %>
                <a href="$InstallURL" class="btn btn-success" onclick="return confirm('Install test images to the database?');">
                    Install Test Images &amp; Run Test
                </a>
            </div>

            <div class="my-4 text-muted setup-divider">or</div>

            <div>
                <p><strong>Option 2:</strong> Use your own images</p>
                <form class="d-flex gap-2 justify-content-center flex-wrap" method="get">
                    <input type="number" name="svg" class="form-control" placeholder="SVG ID" required style="width: 120px;">
                    <input type="number" name="png" class="form-control" placeholder="PNG ID" required style="width: 120px;">
                    <button type="submit" class="btn btn-primary">Run Test</button>
                </form>
                <p class="small text-muted mt-2">Set CropData on images via AssetAdmin first</p>
            </div>
        </div>
    </div>

<% else %>

    <%-- COMPARISON PAGE --%>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <h1 class="h4 mb-0">$Title</h1>
        <div>
            <% if $UsingTestImages %>
                <a href="$RemoveURL" class="btn btn-danger btn-sm" onclick="return confirm('Remove all test images?');">
                    Remove Test Images
                </a>
            <% else %>
                <a href="{$Top.Link}" class="btn btn-dark btn-sm">&larr; Back to setup</a>
            <% end_if %>
        </div>
    </div>

    <% if not $SVGImage %>
    <div class="alert alert-secondary svg-unsupported-note">
        No SVG sample: the SVG tests need <code>restruct/silverstripe-svg-images</code> (with <code>svg</code> allowed in <code>File.allowed_extensions</code>).
    </div>
    <% end_if %>

    <div class="row">
        <%-- SVG Column, left out when the SVG sample was skipped (#6) --%>
        <% if $SVGImage %>
        <div class="col-6">
            <div class="card mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <strong>SVG Image</strong>
                    <a href="$SVGEditURL" class="btn btn-sm btn-light" target="_blank">Edit in CMS</a>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Original</h6>
                            <div class="image-box mb-2">
                                <img src="$OriginalSVG.URL" alt="Original SVG">
                            </div>
                            <div class="small text-muted">
                                ID: $SVGImage.ID | $OriginalSVG.Dimensions
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6>CropData <% if $SVGHasCropData %><span class="badge bg-success">Set</span><% else %><span class="badge bg-secondary">None</span><% end_if %></h6>
                            <% if $SVGHasCropData %>
                                <div class="crop-data">$SVGCropData</div>
                            <% else %>
                                <p class="text-muted small">No CropData set. <a href="$SVGEditURL" target="_blank">Edit in CMS</a> to add crop selection.</p>
                            <% end_if %>
                            <h6 class="mt-2">FocusPoint <% if $SVGHasFocusPoint %><span class="badge bg-warning text-dark">Set</span><% else %><span class="badge bg-secondary">None</span><% end_if %></h6>
                            <% if $SVGHasFocusPoint %>
                                <div class="crop-data">X: $SVGFocusPointX, Y: $SVGFocusPointY</div>
                            <% else %>
                                <p class="text-muted small">No FocusPoint set.</p>
                            <% end_if %>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered bg-white">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 220px;">Method</th>
                            <th class="text-center">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <% loop $SVGComparisons %>
                        <tr class="<% if $IsCropped %>cropped-row<% end_if %><% if $UsesFocusPoint %> focuspoint-row<% end_if %>">
                            <td class="font-monospace small bg-light">
                                $Label
                                <div class="mt-1">
                                    <% if $IsCropped %><span class="badge bg-success">cropped</span><% end_if %>
                                    <% if $UsesFocusPoint %><span class="badge bg-warning text-dark">focuspoint</span><% end_if %>
                                </div>
                            </td>
                            <% if $Error %>
                                <td class="text-danger small bg-danger-subtle">Error: $Error</td>
                            <% else %>
                                <td class="result-cell text-center">
                                    <% if $HasResult %>
                                        <div class="image-box"><img src="$Result.URL" alt="$Label"></div>
                                        <div class="mt-1 small text-muted">$Result.Dimensions</div>
                                    <% else %>
                                        <span class="text-muted fst-italic">No result</span>
                                    <% end_if %>
                                </td>
                            <% end_if %>
                        </tr>
                        <% end_loop %>
                    </tbody>
                </table>
            </div>
        </div>

        <% end_if %>

        <%-- PNG Column --%>
        <div class="<% if $SVGImage %>col-6<% else %>col-12<% end_if %>">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <strong>PNG Image</strong>
                    <a href="$PNGEditURL" class="btn btn-sm btn-light" target="_blank">Edit in CMS</a>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Original</h6>
                            <div class="image-box mb-2">
                                <img src="$OriginalPNG.URL" alt="Original PNG">
                            </div>
                            <div class="small text-muted">
                                ID: $PNGImage.ID | $OriginalPNG.Dimensions
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6>CropData <% if $PNGHasCropData %><span class="badge bg-success">Set</span><% else %><span class="badge bg-secondary">None</span><% end_if %></h6>
                            <% if $PNGHasCropData %>
                                <div class="crop-data">$PNGCropData</div>
                            <% else %>
                                <p class="text-muted small">No CropData set. <a href="$PNGEditURL" target="_blank">Edit in CMS</a> to add crop selection.</p>
                            <% end_if %>
                            <h6 class="mt-2">FocusPoint <% if $PNGHasFocusPoint %><span class="badge bg-warning text-dark">Set</span><% else %><span class="badge bg-secondary">None</span><% end_if %></h6>
                            <% if $PNGHasFocusPoint %>
                                <div class="crop-data">X: $PNGFocusPointX, Y: $PNGFocusPointY</div>
                            <% else %>
                                <p class="text-muted small">No FocusPoint set.</p>
                            <% end_if %>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered bg-white">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 220px;">Method</th>
                            <th class="text-center">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <% loop $PNGComparisons %>
                        <tr class="<% if $IsCropped %>cropped-row<% end_if %><% if $UsesFocusPoint %> focuspoint-row<% end_if %>">
                            <td class="font-monospace small bg-light">
                                $Label
                                <div class="mt-1">
                                    <% if $IsCropped %><span class="badge bg-success">cropped</span><% end_if %>
                                    <% if $UsesFocusPoint %><span class="badge bg-warning text-dark">focuspoint</span><% end_if %>
                                </div>
                            </td>
                            <% if $Error %>
                                <td class="text-danger small bg-danger-subtle">Error: $Error</td>
                            <% else %>
                                <td class="result-cell text-center">
                                    <% if $HasResult %>
                                        <div class="image-box"><img src="$Result.URL" alt="$Label"></div>
                                        <div class="mt-1 small text-muted">$Result.Dimensions</div>
                                    <% else %>
                                        <span class="text-muted fst-italic">No result</span>
                                    <% end_if %>
                                </td>
                            <% end_if %>
                        </tr>
                        <% end_loop %>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="alert alert-info mt-4">
        <strong>Legend:</strong>
        <span class="badge bg-success ms-2">cropped</span> = Uses CropData before manipulation
        <span class="badge bg-warning text-dark ms-2">focuspoint</span> = Crops around focus point (vs center)
        <br class="mt-2">
        <span class="d-inline-block mt-1" style="border-left: 3px solid #28a745; padding-left: 8px;">Green left border</span> = Cropped method
        <span class="d-inline-block mt-1 ms-3" style="border-left: 3px solid #ffc107; padding-left: 8px;">Yellow left border</span> = FocusPoint method
        <% if not $HasFocusPointModule %>
        <br class="mt-2">
        <strong class="text-warning">Note:</strong> FocusPoint module (<code>jonom/focuspoint</code>) is not installed. FocusFill and CroppedFocusFill methods are not shown.
        <% end_if %>
    </div>

    <% if $UsingTestImages %>
    <div class="alert alert-secondary mt-3">
        <strong>Test Image Guide:</strong>
        <ul class="mb-0 mt-2">
            <li><strong>Dashed lines</strong> mark the crop boundaries (x: 50-150, y: 37-112 = center 100&times;75px)</li>
            <li><strong>White crosshair</strong> marks the FocusPoint (X: 0.25, Y: -0.27) - inside crop area, right of center</li>
            <li><strong>Orange triangle</strong> is near the FocusPoint - should stay visible in FocusFill crops</li>
            <li><strong>Red circle</strong> is left of center - may be cropped in narrow FocusFill</li>
            <li>Compare <code>Fill()</code> vs <code>FocusFill()</code>: Fill crops from center, FocusFill keeps the triangle visible</li>
        </ul>
    </div>
    <% end_if %>

<% end_if %>

</div>
</body>
</html>
