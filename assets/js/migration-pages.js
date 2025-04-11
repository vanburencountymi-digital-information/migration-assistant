document.addEventListener('DOMContentLoaded', function() {
    console.log("Migration Pages JS loaded");

    // Ensure merge button exists before adding event listener
    const mergeButton = document.getElementById("merge-content");
    const closeBtn = document.getElementById('migration-close-btn');
    let processedFiles = new Set();
    
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            const modal = document.getElementById('migration-progress-modal');
            if (modal) modal.style.display = 'none';
        });
    }

    function showProgressBar(totalPages) {
        const modal = document.getElementById('migration-progress-modal');
        const bar = document.getElementById('migration-progress-bar');
        const status = document.getElementById('migration-status');
        const closeBtnContainer = document.getElementById('migration-close-btn-container');
        
        if (!modal || !bar || !status) {
            console.error("Progress bar elements not found");
            return;
        }
    
        modal.style.display = 'flex'; // make the modal visible
        bar.style.width = '0%';
        status.textContent = 'Starting...';
        if (closeBtnContainer) closeBtnContainer.style.display = 'none'; // hide close button until complete
    
        window.migrationProgress = {
            total: totalPages,
            completed: 0,
            update: function (message) {
                this.completed++;
                const percent = Math.round((this.completed / this.total) * 100);
                bar.style.width = percent + '%';
                status.textContent = message || `Processing page ${this.completed} of ${this.total}`;
    
                if (this.completed === this.total) {
                    status.textContent = "✅ All pages processed!";
                    if (closeBtnContainer) closeBtnContainer.style.display = 'block';
                }
            }
        };
    }
    
    

    if (mergeButton) {
        // Flag to prevent multiple submissions
        let isProcessing = false;
        // Queue for processing pages
        let pageQueue = [];
        // Track processed pages for tree display
        let processedPages = [];
        
        // Remove any existing click handlers to prevent duplicates
        mergeButton.removeEventListener("click", mergeButtonClickHandler);
        
        // Define the click handler as a named function so we can reference it
        function mergeButtonClickHandler(event) {
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
            
            // Clear any existing queues and processed pages
            pageQueue = [];
            processedPages = [];
            
            let pageId = document.getElementById("existing_page").value;
            let filePath = mergeButton.dataset.file;

            // Gather template selection
            let template = '';
            const templateDropdown = document.querySelector('.page_template_dropdown[data-level="0"]');
            if (templateDropdown) {
                template = templateDropdown.value;
            }
            
            // Explicitly check for the checkbox element and its state
            const buildSubpagesCheckbox = document.getElementById("build-subpages-checkbox");
            let buildSubpages = false;
            
            if (buildSubpagesCheckbox) {
                buildSubpages = buildSubpagesCheckbox.checked;
                console.log("Build subpages checkbox found, state:", buildSubpages);
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
            
            // Log the settings for debugging
            console.log('Processing content with settings:', {
                file: filePath,
                pageId: pageId,
                newPageTitle: newPageTitle,
                template: template,
                buildSubpages: buildSubpages
            });
            
            if (!filePath) {
                alert("No file selected to process.");
                isProcessing = false;
                return;
            }
            
            // Initialize the page queue with the parent page
            pageQueue = [{
                file: filePath,
                pageId: pageId,
                parentId: 0,
                topLevelParent: document.getElementById("top-level-parent").value || 0,
                template: template,
                newPageTitle: newPageTitle,
                isSubpage: false,
                level: 0,
                title: newPageTitle || "Parent Page" // Placeholder title
            }];
            
            console.log("Initial page queue:", pageQueue);
            
            // Start processing the queue
            processNextPage();
        }
        
        // Add the click handler
        mergeButton.addEventListener("click", mergeButtonClickHandler);
        
        // Function to process the next page in the queue
        function processNextPage() {
            console.log("Processing next page. Queue length:", pageQueue.length);
            
            if (pageQueue.length === 0) {
                // Queue is empty, we're done
                if (window.migrationProgress) {
                    window.migrationProgress.update("All pages processed successfully!");
                }
                mergeButton.disabled = false;
                mergeButton.textContent = "Merge Content";
                isProcessing = false;
                
                // Display the tree of processed pages
                displayProcessedPagesTree();
                
                alert("Content processing completed!");
                return;
            }
            
            // Get the next page from the queue
            const page = pageQueue.shift();
            console.log("Next page to process:", page);
            
            // Process this page
            processPage(page);
        }
        
        // Function to process a single page
        function processPage(page) {
            console.log("Processing page:", page);
            
            // Check if this file has already been processed
            if (processedFiles.has(page.file)) {
                console.log("Skipping already processed file:", page.file);
                // Process the next page in the queue
                processNextPage();
                return;
            }
            
            // Mark this file as processed
            processedFiles.add(page.file);
            
            // Update status message to be more descriptive
            if (window.migrationProgress) {
                const pageType = page.isSubpage ? 'subpage' : 'parent page';
                const levelInfo = page.isSubpage ? ` (level ${page.level})` : '';
                window.migrationProgress.update(`Processing ${pageType}: "${page.title || 'Untitled'}"${levelInfo}`);
            }
            
            // Create the data object for page processing
            const postData = {
                action: "merge_content",
                file: page.file,
                page_id: page.pageId,
                parent_id: page.parentId,
                top_level_parent: page.topLevelParent,
                template: page.template,
                is_subpage: page.isSubpage ? 'true' : 'false',
                new_page_title: page.newPageTitle
            };
            
            console.log("Sending page AJAX data:", postData);
            
            jQuery.ajax({
                url: migrationAdminData.ajax_url,
                type: 'POST',
                data: postData,
                timeout: 120000, // 2-minute timeout for larger pages
                success: function(response) {
                    if (response.success) {
                        console.log("Page processed successfully:", response.data);
                        
                        // Initialize progress bar if this is the first page
                        if (!window.migrationProgress && response.data.total_pages) {
                            showProgressBar(response.data.total_pages);
                        }
                        
                        if (window.migrationProgress) {
                            const pageType = page.isSubpage ? 'subpage' : 'parent page';
                            const levelInfo = page.isSubpage ? ` (level ${page.level})` : '';
                            window.migrationProgress.update(`Processed ${pageType}: "${response.data.title}"${levelInfo}`);
                        }
                        
                        // Add this page to our processed pages list for the tree display
                        processedPages.push({
                            id: response.data.page_id,
                            parentId: page.parentId,
                            title: response.data.title,
                            level: page.level
                        });
                        
                        // Update parent IDs for any children using direct object references
                        if (page.childrenRefs && page.childrenRefs.length > 0) {
                            page.childrenRefs.forEach(child => {
                                child.parentId = response.data.page_id;
                                console.log(`Updated parent ID for child "${child.title}" to ${response.data.page_id}`);
                            });
                        }
                        
                        // Check if we need to process subpages
                        if (response.data.has_subpages && page.isSubpage === false) {
                            // This is a parent page with subpages
                            console.log("Parent page has subpages, flattening tree for processing");
                            
                            // Flatten the subpage tree into a queue
                            const subpageQueue = flattenSubpageTree(
                                response.data.subpage_tree, 
                                response.data.page_id,
                                1  // Start at level 1 for immediate children
                            );
                            
                            // Add subpages to the beginning of the queue
                            pageQueue = subpageQueue.concat(pageQueue);
                            
                            console.log("Updated page queue with subpages:", pageQueue);
                        }
                        
                        // Process the next page in the queue
                        setTimeout(processNextPage, 500); // Small delay to prevent overwhelming the server
                    } else if (response.data && response.data.can_clear) {
                        // This is a lock error that we can clear
                        mergeButton.disabled = false;
                        mergeButton.textContent = "Merge Content";
                        isProcessing = false;
                        
                        if (confirm(response.data.message + "\n\nWould you like to clear the lock and try again?")) {
                            clearLock(page.file);
                        }
                    } else {
                        mergeButton.disabled = false;
                        mergeButton.textContent = "Merge Content";
                        isProcessing = false;
                        
                        const errorMsg = response.data && response.data.message 
                            ? response.data.message 
                            : "Unknown error";
                        
                        console.error("Error processing page:", errorMsg);
                        alert(`Error processing page "${page.title || 'Untitled'}": ${errorMsg}`);
                        
                        // Continue with the next page despite the error
                        if (confirm("Would you like to continue processing the remaining pages?")) {
                            processNextPage();
                        }
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    console.error("AJAX Error:", textStatus, errorThrown);
                    
                    // More detailed error information
                    let errorDetails = "";
                    if (xhr.responseText) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.data && response.data.message) {
                                errorDetails = response.data.message;
                            }
                        } catch (e) {
                            // If we can't parse the JSON, use the raw response text
                            errorDetails = xhr.responseText.substring(0, 200) + "...";
                        }
                    }
                    
                    alert(`AJAX request failed: ${textStatus}\n${errorThrown}\n\nDetails: ${errorDetails}`);
                    
                    mergeButton.disabled = false;
                    mergeButton.textContent = "Merge Content";
                    isProcessing = false;
                    
                    // Option to continue despite the error
                    if (confirm("Would you like to continue processing the remaining pages?")) {
                        processNextPage();
                    }
                }
            });
        }
        
        // Function to flatten the subpage tree into a queue
        function flattenSubpageTree(tree, parentId, level = 1) {
            let flatList = [];
            
            tree.forEach(node => {
                // Get template for this subpage level
                const template = getTemplateForLevel(level);
                
                // Add this node to the flat list
                flatList.push({
                    file: node.path,
                    pageId: 'new_page', // Always create new pages for subpages
                    parentId: parentId, // Use the parent page ID
                    topLevelParent: 0, // Not used for subpages
                    template: template,
                    newPageTitle: '', // Use title from content.json
                    isSubpage: true,
                    level: level,
                    title: node.title
                });
                
                // Recursively add children
                if (node.children && node.children.length > 0) {
                    // We don't know the page ID for this node yet since it hasn't been created,
                    // so we'll need to update the parent ID after processing this node
                    // For now, we'll use a placeholder that we'll update later
                    const childrenWithPlaceholderParent = flattenSubpageTree(node.children, -1, level + 1);
                    
                    // Store the index of this node in the flat list
                    const nodeIndex = flatList.length - 1;
                    
                    // Store direct references to child objects instead of indices
                    flatList[nodeIndex].childrenRefs = [];
                    
                    // Add children to the flat list and store direct references
                    childrenWithPlaceholderParent.forEach(child => {
                        flatList[nodeIndex].childrenRefs.push(child);  // Store object reference
                        flatList.push(child);
                    });
                }
            });
            
            return flatList;
        }
        
        // Function to display the tree of processed pages
        function displayProcessedPagesTree() {
            const container = document.getElementById("subpage-tree-list");
            if (!container) return;
            
            container.innerHTML = ''; // Clear existing content
            container.style.display = 'block';
            
            // Build a tree structure from the flat list of processed pages
            const tree = buildTreeFromFlatList(processedPages);
            
            // Render the tree
            renderTree(tree, container);
        }
        
        // Function to build a tree structure from a flat list of pages
        function buildTreeFromFlatList(flatList) {
            const map = {};
            const roots = [];
            
            // First pass: create a map of all pages by ID
            flatList.forEach(page => {
                map[page.id] = { ...page, children: [] };
            });
            
            // Second pass: build the tree structure
            flatList.forEach(page => {
                if (page.parentId && map[page.parentId]) {
                    // This page has a parent in our map, add it as a child
                    map[page.parentId].children.push(map[page.id]);
                } else {
                    // This is a root page
                    roots.push(map[page.id]);
                }
            });
            
            return roots;
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
    
    function renderTree(tree, container, depth = 0) {
        tree.forEach(node => {
            const li = document.createElement("li");
            li.textContent = `${"—".repeat(depth)} ${node.title}`;
            container.appendChild(li);
    
            if (node.children && node.children.length > 0) {
                const ul = document.createElement("ul");
                container.appendChild(ul);
                renderTree(node.children, ul, depth + 1);
            }
        });
    }
    
    // Populate old pages table
    const oldPagesButton = document.getElementById("populate-old-pages-button");
    const oldPagesLog = document.getElementById("old-pages-log");

    if (oldPagesButton && oldPagesLog) {
        oldPagesButton.addEventListener("click", function () {
            oldPagesLog.textContent = "Populating old pages...";
            oldPagesLog.style.display = "block";

            jQuery.post(migrationAdminData.ajax_url, {
                action: "populate_old_pages"
            }, function (response) {
                if (response.success) {
                    oldPagesLog.textContent = "✅ " + response.data.message;
                    console.log("Old pages populated successfully.");
                } else {
                    oldPagesLog.textContent = "❌ " + (response.data?.message || "Unknown error");
                    console.error("Old pages population error:", response);
                }
            }).fail(function (xhr, status, error) {
                oldPagesLog.textContent = `AJAX request failed: ${status}`;
                console.error("AJAX error:", error);
            });
        });
    }

    // Add a function to handle template selection based on level
    function getTemplateForLevel(level) {
        // Try to get template for this specific level
        const templateDropdown = document.querySelector(`.page_template_dropdown[data-level="${level}"]`);
        if (templateDropdown && templateDropdown.value) {
            return templateDropdown.value;
        }
        
        // Fall back to level 0 template
        const defaultTemplate = document.querySelector(`.page_template_dropdown[data-level="0"]`);
        if (defaultTemplate && defaultTemplate.value) {
            return defaultTemplate.value;
        }
        
        // If no template is found, return empty string
        return '';
    }
});