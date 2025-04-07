document.addEventListener('DOMContentLoaded', function() {
    console.log("Migration Assistant JS loaded");

    initializeTree();
    restoreTreeState();

    // Test localStorage functionality
    try {
        localStorage.setItem("migrationTestKey", "test");
        console.log("localStorage test:", localStorage.getItem("migrationTestKey"));
        localStorage.removeItem("migrationTestKey");
    } catch (e) {
        console.error("localStorage not available:", e);
    }

    // Function to initialize the tree
    function initializeTree() {
        document.querySelectorAll(".toggle-icon").forEach(icon => icon.remove());

        let rootFolders = document.querySelectorAll("#ma-menu > .file-tree");
        rootFolders.forEach(folder => {
            folder.style.display = "block";
            folder.querySelectorAll(".file-tree").forEach(childTree => {
                childTree.style.display = "none";
            });
        });

        document.querySelectorAll(".folder").forEach(folder => {
            let folderLink = folder.querySelector("a.directory");
            let fileTree = folder.querySelector(".file-tree");

            if (fileTree) {
                if (!folderLink.querySelector(".toggle-icon")) {
                    let icon = fileTree.style.display === "none" ? "▶" : "▼";
                    let toggleSpan = document.createElement("span");
                    toggleSpan.className = "toggle-icon";
                    toggleSpan.textContent = icon;
                    folderLink.prepend(toggleSpan);
                }
            }
        });
    }

    // Toggle folder open/close
    function toggleFolder(event) {
        event.preventDefault();
        event.stopPropagation();

        let icon = event.target;
        let folderLink = icon.parentElement;
        let fileTree = folderLink.nextElementSibling;

        if (!fileTree) return;

        if (fileTree.style.display === "none") {
            fileTree.style.display = "block";
            icon.textContent = "▼";
        } else {
            fileTree.style.display = "none";
            icon.textContent = "▶";
        }

        saveTreeState();
    }

    // Save tree state to localStorage
    function saveTreeState() {
        let openPaths = [];

        document.querySelectorAll(".folder").forEach(folder => {
            let folderLink = folder.querySelector("a.directory");
            let fileTree = folder.querySelector(".file-tree");

            if (fileTree && fileTree.style.display !== "none") {
                let path = folderLink.dataset.path;
                if (path) openPaths.push(path);
            }
        });

        try {
            localStorage.setItem("migrationTreeState", JSON.stringify(openPaths));
            console.log("Tree state saved:", openPaths);
        } catch (e) {
            console.error("Error saving tree state:", e);
        }
    }

    // Restore tree state from localStorage
    function restoreTreeState() {
        try {
            let treeState = localStorage.getItem("migrationTreeState");
            if (!treeState) return;

            let openPaths = JSON.parse(treeState);
            openPaths.forEach(path => {
                let folderLink = document.querySelector(`a.directory[data-path='${path}']`);
                if (folderLink) {
                    let fileTree = folderLink.nextElementSibling;
                    if (fileTree) {
                        fileTree.style.display = "block";
                        folderLink.querySelector(".toggle-icon").textContent = "▼";
                    }
                }
            });
        } catch (e) {
            console.error("Error restoring tree state:", e);
        }
    }

    // Add event listeners
    document.querySelectorAll(".folder > a.directory").forEach(link => {
        let icon = link.querySelector(".toggle-icon");
        if (icon) {
            icon.addEventListener("click", toggleFolder);
        }

        link.addEventListener("click", function(e) {
            if (!e.target.classList.contains("toggle-icon")) {
                window.location.href = link.href;
            }
            e.preventDefault();
        });
    });

    // Ensure buttons exist before adding event listeners
    const mergeButton = document.getElementById("merge-content");
    const buildSubpagesButton = document.getElementById("build-subpages");

    if (mergeButton) {
        mergeButton.addEventListener("click", function() {
            let pageId = document.getElementById("existing_page").value;
            let filePath = mergeButton.dataset.file; // Ensure this data attribute is set
            let selectedTopLevelParent = document.getElementById("top-level-parent").value;

            if (!pageId || !filePath) {
                alert("Please select a WordPress page and a file to merge.");
                return;
            }

            jQuery.post(migrationAdminData.ajax_url, {
                action: "merge_content",
                page_id: pageId,
                file: filePath,
                top_level_parent: selectedTopLevelParent
            }, function(response) {
                if (response.success) {
                    alert("Content merged successfully!");
                    location.reload();
                } else {
                    alert("Error merging content: " + response.data.message);
                }
            }).fail(function(xhr, textStatus, errorThrown) {
                console.error("AJAX Error:", textStatus, errorThrown);
                alert("AJAX request failed: " + textStatus);
            });
        });
    }

    if (buildSubpagesButton) {
        buildSubpagesButton.addEventListener("click", function() {
            let parentId = document.getElementById("parent_page").value;
            let template = document.getElementById("template").value;
            let subpages = document.getElementById("subpage-list").value; // Assume a JSON array of subpage filenames

            if (!parentId || !template || !subpages) {
                alert("Please select a parent page, template, and subpages to build.");
                return;
            }

            jQuery.post(migrationAdminData.ajax_url, {
                action: "build_subpages",
                parent_id: parentId,
                template: template,
                subpages: subpages
            }, function(response) {
                if (response.success) {
                    alert("Subpages built successfully!");
                    location.reload();
                } else {
                    alert("Error building subpages: " + response.data.message);
                }
            }).fail(function(xhr, textStatus, errorThrown) {
                console.error("AJAX Error:", textStatus, errorThrown);
                alert("AJAX request failed: " + textStatus);
            });
        });
    }

    // Test Airtable connection and department search
    const airtableButton = document.getElementById("test-airtable-button");
    const airtableLog = document.getElementById("airtable-log");
    const testInput = document.getElementById("test-department-name");
    
    if (airtableButton && airtableLog && testInput) {
        airtableButton.addEventListener("click", function () {
            airtableLog.textContent = "Fetching departments...";
            airtableLog.style.display = "block";
    
            const rawInput = testInput.value.trim();
    
            // Normalization function (remove punctuation, extra spaces, lowercase)
            const normalize = str =>
                str.toLowerCase()
                    .replace(/[^\w\s]/gi, '') // remove punctuation
                    .replace(/\s+/g, ' ')     // collapse multiple spaces
                    .trim();
    
            const normalizedInput = normalize(rawInput);
    
            jQuery.post(migrationAdminData.ajax_url, {
                action: "get_airtable_departments"
            }, function (response) {
                if (response.success && Array.isArray(response.data.departments)) {
                    const departments = response.data.departments;
    
                    if (normalizedInput.length > 0) {
                        const matches = departments.filter(dep =>
                            normalize(dep.name).includes(normalizedInput)
                        );
    
                        if (matches.length > 0) {
                            const output = matches.map(dep => `${dep.name} (ID: ${dep.id})`).join('\n');
                            airtableLog.textContent = `✅ Partial matches found:\n` + output;
                        } else {
                            airtableLog.textContent = `❌ No match found for "${rawInput}"`;
                        }
                    } else {
                        // No input → show full list
                        const output = departments.map(dep => `${dep.name} (${dep.id})`).join('\n');
                        airtableLog.textContent = output;
                    }
                } else {
                    airtableLog.textContent = "Error fetching departments.";
                    console.error("Fetch error:", response);
                }
            }).fail(function (xhr, status, error) {
                airtableLog.textContent = `AJAX request failed: ${status}`;
                console.error("AJAX error:", error);
            });
        });
    }
    
    

});
