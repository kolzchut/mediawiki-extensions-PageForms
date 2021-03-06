<?php
/**
 * A special page holding a form that allows the user to create a semantic
 * property.
 *
 * @author Yaron Koren
 * @author Sanyam Goyal
 * @file
 * @ingroup PF
 */

/**
 * @ingroup PFSpecialPages
 */
class PFCreateClass extends SpecialPage {

	public function __construct() {
		parent::__construct( 'CreateClass', 'createclass' );
	}

	public function doesWrites() {
		return true;
	}

	function createAllPages() {
		$out = $this->getOutput();
		$req = $this->getRequest();
		$user = $this->getUser();

		$template_name = trim( $req->getVal( "template_name" ) );
		$template_multiple = $req->getBool( "template_multiple" );
		$use_cargo = trim( $req->getBool( "use_cargo" ) );
		$cargo_table = trim( $req->getVal( "cargo_table" ) );
		// If this is a multiple-instance template, there
		// shouldn't be a corresponding form or category.
		if ( $template_multiple ) {
			$form_name = null;
			$category_name = null;
		} else {
			$form_name = trim( $req->getVal( "form_name" ) );
			$category_name = trim( $req->getVal( "category_name" ) );
		}
		if ( $template_name === '' || ( !$template_multiple && $form_name === '' ) ||
			( $use_cargo && ( $cargo_table === '' ) ) ) {
			$out->addWikiMsg( 'pf_createclass_missingvalues' );
			return;
		}
		$fields = [];
		$jobs = [];
		$allowedValuesForFields = [];
		// Cycle through all the rows passed in.
		for ( $i = 1; $req->getVal( "field_name_$i" ) != ''; $i++ ) {
			// Go through the query values, setting the appropriate
			// local variables.
			$field_name = trim( $req->getVal( "field_name_$i" ) );
			$property_name = trim( $req->getVal( "property_name_$i" ) );
			$property_type = $req->getVal( "property_type_$i" );
			$allowed_values = $req->getVal( "allowed_values_$i" );
			$is_list = $req->getCheck( "is_list_$i" );
			$is_hierarchy = $req->getCheck( "is_hierarchy_$i" );
			// Create an PFTemplateField object based on these
			// values, and add it to the $fields array.
			$field = PFTemplateField::create( $field_name, $field_name, $property_name, $is_list );

			if ( defined( 'CARGO_VERSION' ) ) {
				// Hopefully it's safe to use a Cargo
				// utility method here.
				$possibleValues = CargoUtils::smartSplit( ',', $allowed_values );
				if ( $is_hierarchy ) {
					$field->setHierarchyStructure( $req->getVal( 'hierarchy_structure_' . $i ) );
				} else {
					$field->setPossibleValues( $possibleValues );
				}
				if ( $use_cargo ) {
					$field->setFieldType( $property_type );
					$field->setPossibleValues( $possibleValues );
				} else {
					if ( $allowed_values != '' ) {
						$allowedValuesForFields[$field_name] = $allowed_values;
					}
				}

			}

			$fields[] = $field;

			// Create the property, and make a job for it.
			if ( defined( 'SMW_VERSION' ) && !empty( $property_name ) ) {
				$property_title = Title::makeTitleSafe( SMW_NS_PROPERTY, $property_name );
				$full_text = PFCreateProperty::createPropertyText( $property_type, $allowed_values );
				$params = [
					'user_id' => $user->getId(),
					'page_text' => $full_text,
					'edit_summary' => $this->msg( 'pf_createproperty_editsummary', $property_type )->inContentLanguage()->text()
				];
				$jobs[] = new PFCreatePageJob( $property_title, $params );
			}
		}

		// Also create the "connecting property", if there is one.
		$connectingProperty = trim( $req->getVal( 'connecting_property' ) );
		if ( defined( 'SMW_VERSION' ) && $connectingProperty != '' ) {
			global $smwgContLang;
			$property_title = Title::makeTitleSafe( SMW_NS_PROPERTY, $connectingProperty );
			$datatypeLabels = $smwgContLang->getDatatypeLabels();
			$property_type = $datatypeLabels['_wpg'];
			$full_text = PFCreateProperty::createPropertyText( $property_type, $allowed_values );
			$params = [
				'user_id' => $user->getId(),
				'page_text' => $full_text,
				'edit_summary' => $this->msg( 'pf_createproperty_editsummary', $property_type )->inContentLanguage()->text()
			];
			$jobs[] = new PFCreatePageJob( $property_title, $params );
		}

		// Create the template, and save it (might as well save
		// one page, instead of just creating jobs for all of them).
		$template_format = $req->getVal( "template_format" );
		$pfTemplate = new PFTemplate( $template_name, $fields );
		if ( defined( 'CARGO_VERSION' ) && $use_cargo ) {
			$pfTemplate->setCargoTable( $cargo_table );
		}
		if ( defined( 'SMW_VERSION' ) && $template_multiple ) {
			$pfTemplate->setConnectingProperty( $connectingProperty );
		} else {
			$pfTemplate->setCategoryName( $category_name );
		}
		$pfTemplate->setFormat( $template_format );
		$full_text = $pfTemplate->createText();

		$template_title = Title::makeTitleSafe( NS_TEMPLATE, $template_name );
		$template_page = WikiPage::factory( $template_title );
		$edit_summary = '';
		PFCreatePageJob::createOrModifyPage( $template_page, $full_text, $edit_summary, $user );

		// Create the form, and make a job for it.
		if ( $form_name != '' ) {
			$formFields = [];
			foreach ( $fields as $field ) {
				$formField = PFFormField::create( $field );
				$fieldName = $field->getFieldName();
				if ( array_key_exists( $fieldName, $allowedValuesForFields ) ) {
					$formField->setInputType( 'dropdown' );
					$formField->setFieldArg( 'values', $allowedValuesForFields[$fieldName] );
				}
				$formFields[] = $formField;
			}
			$form_template = PFTemplateInForm::create( $template_name, '', false, false, $formFields );
			$form_items = [];
			$form_items[] = [
				'type' => 'template',
				'name' => $form_template->getTemplateName(),
				'item' => $form_template
			];

			$form_title = Title::makeTitleSafe( PF_NS_FORM, $form_name );
			$form = PFForm::create( $form_name, $form_items );
			if ( $category_name != '' ) {
				$form->setAssociatedCategory( $category_name );
			}
			$full_text = $form->createMarkup();
			$params = [
				'user_id' => $user->getId(),
				'page_text' => $full_text
			];
			$jobs[] = new PFCreatePageJob( $form_title, $params );
		}

		// Create the category, and make a job for it.
		if ( $category_name != '' ) {
			$full_text = PFCreateCategory::createCategoryText( $form_name, $category_name, '' );
			$category_title = Title::makeTitleSafe( NS_CATEGORY, $category_name );
			$params = [
				'user_id' => $user->getId(),
				'page_text' => $full_text
			];
			$jobs[] = new PFCreatePageJob( $category_title, $params );
		}

		JobQueueGroup::singleton()->push( $jobs );

		$out->addWikiMsg( 'pf_createclass_success' );
	}

	function execute( $query ) {
		global $wgLang, $smwgContLang;

		$out = $this->getOutput();
		$req = $this->getRequest();

		// Check permissions.
		if ( !$this->getUser()->isAllowed( 'createclass' ) ) {
			$this->displayRestrictionError();
			return;
		}

		$this->setHeaders();

		$numStartingRows = 5;
		$out->addJsConfigVars( '$numStartingRows', $numStartingRows );
		$out->addModules( [ 'ext.pageforms.PF_CreateClass' ] );

		$createAll = $req->getCheck( 'createAll' );
		if ( $createAll ) {
			// Guard against cross-site request forgeries (CSRF).
			$validToken = $this->getUser()->matchEditToken( $req->getVal( 'csrf' ), 'CreateClass' );
			if ( !$validToken ) {
				$text = "This appears to be a cross-site request forgery; canceling save.";
				$out->addHTML( $text );
				return;
			}

			$this->createAllPages();
			return;
		}

		$specialBGColor = '#eeffcc';
		if ( defined( 'SMW_VERSION' ) ) {
			$possibleTypes = $smwgContLang->getDatatypeLabels();
		} elseif ( defined( 'CARGO_VERSION' ) ) {
			global $wgCargoFieldTypes;
			$possibleTypes = $wgCargoFieldTypes;
			$specialBGColor = '';
		} else {
			$possibleTypes = [];
		}

		// Make links to all the other 'Create...' pages, in order to
		// link to them at the top of the page.
		$creation_links = [];
		$linkRenderer = $this->getLinkRenderer();
		if ( defined( 'SMW_VERSION' ) ) {
			$creation_links[] = PFUtils::linkForSpecialPage( $linkRenderer, 'CreateProperty' );
		}
		$creation_links[] = PFUtils::linkForSpecialPage( $linkRenderer, 'CreateTemplate' );
		$creation_links[] = PFUtils::linkForSpecialPage( $linkRenderer, 'CreateForm' );
		$creation_links[] = PFUtils::linkForSpecialPage( $linkRenderer, 'CreateCategory' );

		$text = '<form id="createClassForm" action="" method="post">' . "\n";
		$text .= "\t" . Html::rawElement( 'p', null,
				$this->msg( 'pf_createclass_docu' )
					->rawParams( $wgLang->listToText( $creation_links ) )
					->escaped() ) . "\n";
		$templateNameLabel = $this->msg( 'pf_createtemplate_namelabel' )->escaped();
		$templateNameInput = Html::input( 'template_name', null, 'text', [ 'size' => 30 ] );
		$text .= "\t" . Html::rawElement( 'p', null, $templateNameLabel . ' ' . $templateNameInput ) . "\n";

		$templateInfo = '';
		if ( defined( 'CARGO_VERSION' ) && !defined( 'SMW_VERSION' ) ) {
			$templateInfo .= "\t<p><label>" .
				Html::check( 'use_cargo', true, [ 'id' => 'use_cargo' ] ) .
				' ' . $this->msg( 'pf_createtemplate_usecargo' )->escaped() .
				"</label></p>\n";
			$cargo_table_label = $this->msg( 'pf_createtemplate_cargotablelabel' )->escaped();
			$templateInfo .= "\t" . Html::rawElement( 'p', [ 'id' => 'cargo_table_input' ],
				Html::element( 'label', [ 'for' => 'cargo_table' ], $cargo_table_label ) . ' ' .
				Html::element( 'input', [ 'size' => '30', 'name' => 'cargo_table', 'id' => 'cargo_table' ], null )
			) . "\n";
		}
		$createTemplatePage = new PFCreateTemplate();
		$templateInfo .= $createTemplatePage->printTemplateStyleInput( 'template_format' );
		$templateInfo .= Html::rawElement( 'p', null,
			Html::element( 'input', [
				'type' => 'checkbox',
				'name' => 'template_multiple',
				'id' => 'template_multiple',
				'class' => "disableFormAndCategoryInputs",
			] ) . ' ' . $this->msg( 'pf_createtemplate_multipleinstance' )->escaped() ) . "\n";
		// Either #set_internal or #subobject will be added to the
		// template, depending on whether Semantic Internal Objects is
		// installed.
		global $smwgDefaultStore;
		if ( defined( 'SIO_VERSION' ) || $smwgDefaultStore == "SMWSQLStore3" ) {
			$templateInfo .= Html::rawElement( 'div',
				[
					'id' => 'connecting_property_div',
					'style' => 'display: none;',
				],
				$this->msg( 'pf_createtemplate_connectingproperty' )->escaped() . "\n" .
				Html::element( 'input', [
					'type' => 'text',
					'name' => 'connecting_property',
				] ) ) . "\n";
		}
		$text .= Html::rawElement( 'blockquote', null, $templateInfo );

		$form_name_input = Html::element( 'input', [ 'size' => '30', 'name' => 'form_name', 'id' => 'form_name' ], null );
		$text .= "\t<p><label>" . $this->msg( 'pf_createclass_nameinput' )->escaped() . " $form_name_input</label></p>\n";
		$category_name_input = Html::element( 'input', [ 'size' => '30', 'name' => 'category_name', 'id' => 'category_name' ], null );
		$text .= "\t<p><label>" . $this->msg( 'pf_createcategory_name' )->escaped() . " $category_name_input</label></p>\n";
		$text .= "\t" . Html::element( 'br', null, null ) . "\n";
		$text .= <<<END
	<div>
		<table id="mainTable" style="border-collapse: collapse;">

END;
		if ( defined( 'SMW_VERSION' ) ) {
			$property_label = $this->msg( 'smw_pp_type' )->escaped();
			$text .= <<<END
		<tr>
			<th colspan="3" />
			<th colspan="3" style="background: #ddeebb; padding: 4px;">$property_label</th>
		</tr>

END;
		}

		$field_name_label = $this->msg( 'pf_createtemplate_fieldname' )->escaped();
		$list_of_values_label = $this->msg( 'pf_createclass_listofvalues' )->escaped();
		$text .= <<<END
		<tr>
			<th colspan="2">$field_name_label</th>
			<th style="padding: 4px;">$list_of_values_label</th>

END;
		if ( defined( 'SMW_VERSION' ) ) {
			$text .= $this->displayTableHeader( $specialBGColor, 'pf_createproperty_propname' );
		}
		if ( defined( 'CARGO_VERSION' ) || defined( 'SMW_VERSION' ) ) {
			$text .= $this->displayTableHeader( $specialBGColor, 'pf_createproperty_proptype' );
		}
		$text .= $this->displayTableHeader( $specialBGColor, 'pf_createclass_allowedvalues' );
		if ( defined( 'CARGO_VERSION' ) && !defined( 'SMW_VERSION' ) ) {
			$text .= $this->displayTableHeader( $specialBGColor, 'pf_createclass_ishierarchy' );
		}
		$text .= <<<END
			</tr>

END;
		// Make one more row than what we're displaying - use the
		// last row as a "starter row", to be cloned when the
		// "Add another" button is pressed.
		for ( $i = 1; $i <= $numStartingRows + 1; $i++ ) {
			if ( $i == $numStartingRows + 1 ) {
				$rowString = 'id="starterRow" style="display: none"';
				$n = 'starter';
			} else {
				$rowString = '';
				$n = $i;
			}
			$text .= <<<END
		<tr $rowString style="margin: 4px;">
			<td>$n.</td>
			<td><input type="text" size="25" name="field_name_$n" /></td>
			<td style="text-align: center;"><input type="checkbox" name="is_list_$n" /></td>

END;
			if ( defined( 'SMW_VERSION' ) ) {
				$propertyInput = Html::input( "property_name_$n", null, 'text', [ 'size' => '25' ] );
				$text .= Html::rawElement( 'td', [ 'style' => "background: $specialBGColor; padding: 4px;" ], $propertyInput );
			}
			if ( defined( 'CARGO_VERSION' ) || defined( 'SMW_VERSION' ) ) {
				$typeDropdownBody = '';
				foreach ( $possibleTypes as $typeName ) {
					$typeDropdownBody .= "\t\t\t\t<option>$typeName</option>\n";
				}
				$typeDropdown = "\t\t\t\t" . Html::rawElement( 'select', [ 'name' => "property_type_$n" ], $typeDropdownBody ) . "\n";
				$text .= Html::rawElement( 'td', [ 'style' => "background: $specialBGColor; padding: 4px;" ], $typeDropdown );
			}

			$text .= <<<END
			<td style="background: $specialBGColor; padding: 4px;">
			<input type="text" size="25" name="allowed_values_$n" />
END;
			if ( defined( 'CARGO_VERSION' ) ) {
				$text .= Html::element( 'textarea', [
						'class' => 'hierarchy_structure',
						'rows' => '10',
						'cols' => '20',
						'name' => "hierarchy_structure_$n",
						'style' => 'display: none'
					], null );
			}
			$text .= <<<END
			</td>
END;
			if ( defined( 'CARGO_VERSION' ) ) {
				$isHierarchyInput = Html::input( "is_hierarchy_$n", null, 'checkbox' );
				$text .= Html::rawElement( 'td', [ 'style' => 'text-align: center;' ], $isHierarchyInput );
			}
		}
		$text .= <<<END
		</tr>
		</table>
	</div>

END;
		$add_another_button = Html::element( 'input',
			[
				'type' => 'button',
				'value' => $this->msg( 'pf_formedit_addanother' )->text(),
				'class' => "createClassAddRow mw-ui-button mw-ui-progressive"
			]
		);
		$text .= Html::rawElement( 'p', null, $add_another_button ) . "\n";
		// Set 'title' as hidden field, in case there's no URL niceness
		$cc = $this->getPageTitle();
		$text .= Html::hidden( 'title', PFUtils::titleURLString( $cc ) );

		$text .= "\t" . Html::hidden( 'csrf', $this->getUser()->getEditToken( 'CreateClass' ) ) . "\n";

		$text .= Html::element( 'input',
			[
				'type' => 'submit',
				'name' => 'createAll',
				'value' => $this->msg( 'Pf_createclass_create' )->text(),
				'class' => 'mw-ui-button mw-ui-progressive'
			]
		);
		$text .= "</form>\n";
		$out->addHTML( $text );
	}

	private function displayTableHeader( $bgColor, $headerMsg ) {
		$headerText = $this->msg( $headerMsg )->parse();
		return Html::element( 'th', [ 'style' => "background: $bgColor; padding: 4px;" ], $headerText ) . "\n";
	}

	protected function getGroupName() {
		return 'pf_group';
	}
}
