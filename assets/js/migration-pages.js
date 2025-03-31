document.addEventListener('DOMContentLoaded', function() {
    console.log("Migration Pages JS loaded");

    // Ensure merge button exists before adding event listener
    const mergeButton = document.getElementById("merge-content");
    
    if (mergeButton) {
        // Flag to prevent multiple submissions
        let isProcessing = false;
        
        mergeButton.addEventListener("click", function(event) {
            // Prevent default button behavior
            event.preventDefault();
            
            // Prevent multiple submissions
            if (isProcessing) {
                console.log("Already processing a request, ignoring click");
                alert("A request is already being processed. Please wait.");
                return;
            }
            
            isProcessing = true;
            console.log("Starting content processing...");
            
            let pageId = document.getElementById("existing_page").value;
            let filePath = mergeButton.dataset.file;
            let template = document.getElementById("page_template") ? 
                           document.getElementById("page_template").value : '';
            
            // Explicitly check for the checkbox element and its state
            const buildSubpagesCheckbox = document.getElementById("build-subpages-checkbox");
            let buildSubpages = 'false';
            
            if (buildSubpagesCheckbox) {
                buildSubpages = buildSubpagesCheckbox.checked ? 'true' : 'false';
                console.log("Build subpages checkbox found, state:", buildSubpages, "checked:", buildSubpagesCheckbox.checked);
            } else {
                console.log("Build subpages checkbox not found");
            }
            
            // Check if we're creating a new page - but don't require a title
            let newPageTitle = '';
            if (pageId === 'new_page') {
                // Get the title from the input if it exists, but don't require it
                if (document.getElementById("new_page_title")) {
                    newPageTitle = document.getElementById("new_page_title").value;
                }
                // We'll use the title from the content.json file if this is empty
            } else if (!pageId) {
                alert("Please select a destination page or choose to create a new page.");
                isProcessing = false;
                return;
            }
            
            console.log('Processing content with settings:');
            console.log('File:', filePath);
            console.log('Page ID/Option:', pageId);
            console.log('New Page Title:', newPageTitle);
            console.log('Template:', template);
            console.log('Build Subpages:', buildSubpages);
            
            if (!filePath) {
                alert("No file selected to process.");
                isProcessing = false;
                return;
            }
            
            // Disable the button to prevent multiple clicks
            mergeButton.disabled = true;
            mergeButton.textContent = "Processing parent page...";
            
            // Step 1: Process the parent page first
            processParentPage(pageId, filePath, template, newPageTitle, buildSubpages);
        });
        
        // Function to process the parent page
        function processParentPage(pageId, filePath, template, newPageTitle, buildSubpages) {
            // Create the data object for parent page processing
            const postData = {
                action: "merge_content",
                process_type: "parent",
                page_id: pageId,
                file: filePath,
                template: template,
                build_subpages: buildSubpages,
                new_page_title: newPageTitle
            };
            
            console.log("Sending parent page AJAX data:", postData);
            
            jQuery.ajax({
                url: migrationAdminData.ajax_url,
                type: 'POST',
                data: postData,
                success: function(response) {
                    if (response.success) {
                        console.log("Parent page processed successfully:", response.data);
                        
                        // Check if we need to process subpages
                        if (response.data.has_subpages) {
                            mergeButton.textContent = "Processing subpages...";
                            
                            // Add a small delay to ensure the parent page is fully saved
                            setTimeout(function() {
                                // Step 2: Process subpages after parent page is created
                                processSubpages(response.data.parent_page_id, filePath, template);
                            }, 500); // 500ms delay
                        } else {
                            // No subpages to process, we're done
                            mergeButton.disabled = false;
                            mergeButton.textContent = "Merge Content";
                            isProcessing = false;
                            
                            alert("Content processed successfully!");
                            location.reload();
                        }
                    } else {
                        // Check if this is the expected error from the legacy format rejection
                        if (response.data && response.data.message === 'Please refresh the page and try again.') {
                            console.log("Legacy format rejection detected - this is expected and will be handled silently");
                            // Do nothing - this is expected and will be handled by the new format request
                        } else if (response.data && response.data.can_clear) {
                            // This is a lock error that we can clear
                            mergeButton.disabled = false;
                            mergeButton.textContent = "Merge Content";
                            isProcessing = false;
                            
                            if (confirm(response.data.message + "\n\nWould you like to clear the lock and try again?")) {
                                clearLock(filePath);
                            }
                        } else {
                            mergeButton.disabled = false;
                            mergeButton.textContent = "Merge Content";
                            isProcessing = false;
                            
                            alert("Error processing parent page: " + (response.data ? response.data.message : "Unknown error"));
                        }
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    mergeButton.disabled = false;
                    mergeButton.textContent = "Merge Content";
                    isProcessing = false;
                    
                    console.error("AJAX Error:", textStatus, errorThrown);
                    alert("AJAX request failed: " + textStatus);
                }
            });
        }
        
        // Function to process subpages
        function processSubpages(parentPageId, filePath, template) {
            // Create the data object for subpage processing
            const postData = {
                action: "merge_content",
                process_type: "subpages",
                parent_page_id: parentPageId,
                file: filePath,
                template: template
            };
            
            console.log("Sending subpages AJAX data:", postData);
            
            jQuery.ajax({
                url: migrationAdminData.ajax_url,
                type: 'POST',
                data: postData,
                success: function(response) {
                    mergeButton.disabled = false;
                    mergeButton.textContent = "Merge Content";
                    isProcessing = false;
                    
                    if (response.success) {
                        alert("Content processed successfully! Created " + response.data.count + " subpages.");
                        location.reload();
                    } else if (response.data && response.data.can_clear) {
                        // This is a lock error that we can clear
                        if (confirm(response.data.message + "\n\nWould you like to clear the lock and try again?")) {
                            clearLock(filePath);
                        }
                    } else {
                        alert("Error processing subpages: " + (response.data ? response.data.message : "Unknown error"));
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    mergeButton.disabled = false;
                    mergeButton.textContent = "Merge Content";
                    isProcessing = false;
                    
                    console.error("AJAX Error:", textStatus, errorThrown);
                    alert("AJAX request failed: " + textStatus);
                }
            });
        }
        
        // Function to clear a lock
        function clearLock(filePath) {
            const postData = {
                action: "merge_content",
                clear_lock: "true",
                file: filePath
            };
            
            console.log("Clearing lock for file:", filePath);
            
            jQuery.ajax({
                url: migrationAdminData.ajax_url,
                type: 'POST',
                data: postData,
                success: function(response) {
                    if (response.success) {
                        alert("Lock cleared successfully. You can now try again.");
                    } else {
                        alert("Error clearing lock: " + (response.data ? response.data.message : "Unknown error"));
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    console.error("AJAX Error:", textStatus, errorThrown);
                    alert("Failed to clear lock: " + textStatus);
                }
            });
        }
    }
    
    // Show/hide new page title input based on dropdown selection
    const pageSelector = document.getElementById("existing_page");
    if (pageSelector) {
        pageSelector.addEventListener("change", function() {
            const newPageTitleContainer = document.getElementById("new-page-title-container");
            if (newPageTitleContainer) {
                if (this.value === 'new_page') {
                    newPageTitleContainer.style.display = 'block';
                } else {
                    newPageTitleContainer.style.display = 'none';
                }
            }
        });
    }
});