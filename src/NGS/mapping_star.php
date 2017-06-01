<?php

/**
	@page mapping_star
 */

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

// parse command line arguments
$parser = new ToolBase("mapping_star", "Alignment of RNA-seq FASTQ files to a reference genome.");
$parser->addInfile("in1", "Input forward reads in FASTQ(.GZ) format.", false);
$parser->addOutfile("out", "Output BAM file name", false);

//optional arguments
$parser->addInfile("in2",  "Input reverse reads in FASTQ(.GZ) format for paired-end alignment.", true);
$parser->addString("genome", "Path to directory with STAR reference genome index.", true, get_path("data_folder")."genomes/STAR/GRCh37");

$parser->addFlag("stranded", "Add this flag if library preparation was strand conserving.");
$parser->addFlag("uncompressed", "FASTQ input files are uncompressed.");
$parser->addInt("threads", "Number of parallel threads.", true, 4);

$parser->addString("llink", "Adapter forward read", true, "AGATCGGAAGAGCACACGTCTGAACTCCAGTCACGAGTTA");
$parser->addString("rlink", "Adapter reverse read", true, "AGATCGGAAGAGCGTCGTGTAGGGAAAGAGTGTAGATCTC");

$parser->addInt("Clip5p", "Remove n bases from 5' end of read.", true, 0);
$parser->addInt("Clip3p", "Remove n bases from 3' end of read.", true, 0);

$parser->addFlag("dedup", "Marks duplicates after alignment.");

$parser->addInt("Lmax", "Max length of read fraction for seed search", true, 50);

$parser->addFlag("noSplicing", "Prevent reads from getting spliced");

$parser->addFlag("longReads", "Use STAR version suitable for very long reads > 500nt.");

$parser->addFlag("useSharedMemory", "Load STAR Index into shared memory in order to run multiple STAR instances on the same genome.");
$parser->addFlag("clipping", "Perform adapter clipping during mapping.");

$parser->addFlag("disableJunctionFilters", "Disable filtering of the reported junctions (affects splicing output only).");

$parser->addInt("sjOverhang", "Minimum overhang for non-annotated splice junctions.", true, 8);
$parser->addInt("sjdbOverhang", "Minimum overhang for annotated splice junctions.", true, 1);

$downstream_all = array("splicing","chimeric");
$parser->addString("downstream", "Keep files for downstream analysis.", true, "");

extract($parser->parse($argv));

$outdir = realpath(dirname($out))."/";
$prefix = $outdir.basename($out, ".bam");

// Temporary prefix where STAR stores his intermediate files. The directory has to be removed before running STAR, otherwise STAR complains.
$STAR_tmp_folder = $parser->tempFolder();

//build command
$arguments = array();
$arguments[] = "--genomeDir $genome";
$arguments[] = "--readFilesIn $in1";

//for paired-end mapping
if(isset($in2))
{
  $arguments[] = "$in2";
}

$arguments[] = "--outFileNamePrefix {$STAR_tmp_folder}/";
$arguments[] = "--outStd SAM";

$arguments[] = "--runThreadN $threads";

if($clipping) {
	$arguments[] = "--clip3pAdapterSeq $llink $rlink";
}

$arguments[] = "--clip3pNbases $Clip3p --clip5pNbases $Clip5p";
$arguments[] = "--outSAMattributes All";

if(!$stranded) {
  $arguments[] = " --outSAMstrandField intronMotif";
}

if(!$uncompressed) {
  $arguments[] = "--readFilesCommand zcat";
}

//options for chimeric alignment detection
$arguments[] = "--chimSegmentMin 12 --chimJunctionOverhangMin 12 --chimSegmentReadGapMax 3";
$arguments[] = "--seedSearchStartLmax $Lmax --alignMatesGapMax 1000000 --alignIntronMax 1000000 --alignIntronMin 20";
$arguments[] = "--alignSJoverhangMin $sjOverhang --alignSJDBoverhangMin $sjdbOverhang --alignSJstitchMismatchNmax 5 -1 5 5";

if ($disableJunctionFilters) {
	$arguments[] = "--outSJfilterDistToOtherSJmin 0 0 0 0 --outSJfilterOverhangMin 1 1 1 1 --outSJfilterCountUniqueMin 1 1 1 1 --outSJfilterCountTotalMin 1 1 1 1";
}

//2-pass mode is only possible if shared memory is not used
if($useSharedMemory) {
	$arguments[] = "--genomeLoad LoadAndRemove";
} else {
	$arguments[] = "--genomeLoad NoSharedMemory";
	$arguments[] = "--twopassMode Basic";
}

if($noSplicing) {
	//min has to be larger than max, to prevent spliced mapping
	$arguments[] = "--alignIntronMin 2";
	$arguments[] = "--alignIntronMax 1";
}

//add a read group line to the final BAM file
$group_props = array();
$basename = basename($out, ".bam");
list($gs, $ps) = explode("_", $basename."_99");
$group_props[] = "ID:{$basename}";
$group_props[] = "SM:{$gs}";
$group_props[] = "LB:{$gs}_{$ps}";
$group_props[] = "CN:medical_genetics_tuebingen";
$group_props[] = "DT:".date("c");
$group_props[] = "PL:ILLUMINA";
$arguments[] = "--outSAMattrRGline ".implode("\t", $group_props);

// keep unmapped reads in BAM output
$arguments[] = "--outSAMunmapped Within";

//STAR or STARlong program
$STAR = get_path("STAR");
if($longReads)
{
	$STAR = get_path("STAR")."long";
}

//mapping with STAR
$pipeline = array();
$pipeline[] = array($STAR, implode(" ", $arguments));

//duplicate removal with samblaster
if ($dedup)
{
	$pipeline[] = array(get_path("samblaster"), "");
}

//convert sam to bam (uncompressed) with samtools
$pipeline[] = array(get_path("samtools"), "view -hu -");

//sort BAM according to coordinates
$tmp_for_sorting = $parser->tempFile();
$pipeline[] = array(get_path("samtools"), "sort -T $tmp_for_sorting -m 1G -@ ".min($threads, 4)." -o $out -", true);

//execute (STAR -> samblaster -> samtools SAM to BAM -> samtools sort)
$parser->execPipeline($pipeline, "mapping");

//keep downstream analysis files
$downstream = explode(",", $downstream);
if (in_array("splicing", $downstream))
{
	$parser->exec("cp", "{$STAR_tmp_folder}/SJ.out.tab {$prefix}_splicing.tsv", true);	
}
if (in_array("chimeric", $downstream))
{
	$parser->exec("cp", "{$STAR_tmp_folder}/Chimeric.out.junction {$prefix}_chimeric.tsv", true);
}

//write the final log file into the tool log
$final_log = "{$STAR_tmp_folder}/Log.final.out";
$parser->log("STAR final log", file($final_log));

//create index file
$parser->exec(get_path("ngs-bits")."BamIndex", "-in $out", true);

?>